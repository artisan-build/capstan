<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Livewire\Postmaster\SpokeMap;
use App\Models\Inbox;
use App\Models\MachineTeamWorkSetting;
use App\Models\MachineUsageReport;
use App\Models\MachineWorkSettingChange;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkMilestone;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('m', 32)),
        'capstan.postmaster.map.stale_after_seconds' => 300,
    ]);
    Date::setTestNow('2026-08-17 12:00:00');
});

afterEach(function (): void {
    Date::setTestNow();
});

function createMapSpoke(
    User $user,
    ?string $name,
    ?CarbonInterface $lastPolledAt,
    SpokeLiveness $probeStatus,
    int $inboxes = 0,
    ?string $deviceName = null,
): Spoke {
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $user->id,
        'credential_id' => (string) Str::uuid(),
        'name' => $name,
        'last_polled_at' => $lastPolledAt,
        'probe_status' => $probeStatus,
    ]);

    if ($deviceName !== null) {
        $spoke->forceFill(['device_name' => $deviceName])->save();
    }

    for ($index = 1; $index <= $inboxes; $index++) {
        $inbox = Inbox::query()->create([
            'actor_id' => (string) $user->id,
            'local_part' => "map-{$spoke->id}-{$index}",
        ]);
        $spoke->inboxes()->attach($inbox);
    }

    return $spoke;
}

test('the authenticated map page renders registered spoke data', function (): void {
    $owner = capstanUser(['name' => 'Map Owner', 'role' => UserRole::Owner->value]);
    $spoke = createMapSpoke($owner, 'rendered-spoke', now(), SpokeLiveness::Green, 2);

    $this->actingAsVersioned($owner)
        ->get(route('postmaster.map'))
        ->assertOk()
        ->assertSee($spoke->name)
        ->assertSee('Owned by Map Owner')
        ->assertSeeHtml('wire:poll.30s')
        ->assertSeeHtml('data-testid="postmaster-onboarding-row"')
        ->assertSeeHtml('data-status="green"')
        ->assertSeeHtml('data-inbox-count="2"');

    $document = new DOMDocument;
    @$document->loadHTML($this->get(route('postmaster.map'))->content());
    $row = (new DOMXPath($document))->query('//*[@data-testid="postmaster-onboarding-row"]')->item(0);
    expect($row)->not->toBeNull()
        ->and((new DOMXPath($document))->query('.//button', $row)->length)->toBe(1);
});

test('usage tool guidance is limited to owners and admins when selected-team usage is unknown or unavailable', function (UserRole $role, ?string $availability, bool $visible): void {
    $viewer = capstanUser(['role' => $role->value]);
    $machine = createMapSpoke($viewer, 'usage-unknown', now(), SpokeLiveness::Green);
    if ($availability !== null) {
        MachineUsageReport::query()->create([
            'spoke_id' => $machine->id,
            'source' => 'none',
            'availability' => $availability,
            'last_attempt_at' => now(),
            'unavailable_since' => now(),
            'failure_code' => 'not_found',
        ]);
    }

    $component = Livewire::actingAs($viewer)->test(SpokeMap::class);

    if ($visible) {
        $component->assertSeeHtml('data-testid="machine-usage-tool-guidance"');
    } else {
        $component->assertDontSeeHtml('data-testid="machine-usage-tool-guidance"');
    }

    MachineUsageReport::query()->updateOrCreate(['spoke_id' => $machine->id], [
        'source' => 'openusage-sh',
        'source_version' => '0.26.1',
        'availability' => 'available',
        'collected_at' => now(),
        'last_attempt_at' => now(),
        'last_success_received_at' => now(),
        'unavailable_since' => null,
        'failure_code' => null,
    ]);

    Livewire::actingAs($viewer)->test(SpokeMap::class)
        ->assertDontSeeHtml('data-testid="machine-usage-tool-guidance"');
})->with([
    'owner with unknown usage' => [UserRole::Owner, null, true],
    'admin with unavailable usage' => [UserRole::Admin, 'unavailable', true],
    'member with unavailable usage' => [UserRole::Member, 'unavailable', false],
]);

test('usage tool guidance renders the pinned verified Linux path and macOS privacy caveat', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    createMapSpoke($owner, 'usage-unavailable', now(), SpokeLiveness::Green);
    $html = Livewire::actingAs($owner)->test(SpokeMap::class)->html();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $guidance = (new DOMXPath($document))->query('//*[@data-testid="machine-usage-tool-guidance"]')->item(0);
    expect($guidance)->not->toBeNull();
    $copy = $guidance?->textContent ?? '';

    expect($copy)
        ->toContain('This build detects tools but never installs them.')
        ->toContain('checksums.txt')
        ->toContain('sha256sum')
        ->toContain('shasum -a 256')
        ->toContain('$HOME/.local/bin/openusage')
        ->toContain('~/bin, /opt/homebrew/bin, /usr/local/bin, and /usr/bin')
        ->toContain('OpenUsage app v0.7.15 bundles PostHog')
        ->toContain('cannot prove that its telemetry can be disabled')
        ->not->toContain('install.sh')
        ->not->toMatch('/curl[^\n]*\|\s*(?:ba)?sh/');

    preg_match_all('/archive=(openusage_[^\s;]+_linux_(amd64|arm64)\.tar\.gz)/', $copy, $recipeArchives);
    expect($recipeArchives[1])->toHaveCount(2)
        ->and($recipeArchives[2])->toBe(['amd64', 'arm64']);

    $manifest = file_get_contents(base_path('tests/Fixtures/openusage-checksums.txt'));
    expect($manifest)->not->toBeFalse();

    foreach ($recipeArchives[1] as $archive) {
        expect(preg_match_all('/^[a-f0-9]{64}  '.preg_quote($archive, '/').'$/m', (string) $manifest))
            ->toBe(1);
    }
});

test('map owner names never fall back to email or expose actor ids', function (): void {
    $viewer = capstanUser(['role' => UserRole::Owner->value]);
    $named = capstanUser(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    $emailOnly = capstanUser(['name' => '  ', 'email' => 'email-only@example.test']);
    $missing = capstanUser();
    $namedSpoke = createMapSpoke($named, 'named-owner', now(), SpokeLiveness::Green);
    $emailSpoke = createMapSpoke($emailOnly, 'email-owner', now(), SpokeLiveness::Green);
    $missingSpoke = createMapSpoke($missing, 'missing-owner', now(), SpokeLiveness::Green);
    MachineTeamWorkSetting::query()->create([
        'spoke_id' => $missingSpoke->id,
        'team_id' => Team::default()->id,
        'enabled' => false,
        'roles' => [],
        'capabilities' => [],
        'role_providers' => [],
        'concurrency_ceiling' => 1,
        'updated_by_actor' => (string) $missing->id,
    ]);
    $missingId = (string) $missing->id;
    $missing->delete();

    $spokes = Livewire::actingAs($viewer)->test(SpokeMap::class)->viewData('spokes')->keyBy('id');

    expect($spokes[$namedSpoke->id]['owner_name'])->toBe('Ada Lovelace')
        ->and($spokes[$emailSpoke->id]['owner_name'])->toBe('Member')
        ->and($spokes[$missingSpoke->id]['owner_name'])->toBe('Former member')
        ->and($spokes->pluck('owner_name'))->not->toContain((string) $named->id)
        ->and($spokes->pluck('owner_name'))->not->toContain((string) $emailOnly->id)
        ->and($spokes->pluck('owner_name'))->not->toContain($missingId);
});

test('display names prefer a custom name then device name and disambiguate owner collisions', function (): void {
    $owner = capstanUser();
    $other = capstanUser();
    $custom = createMapSpoke($owner, 'Desk', now(), SpokeLiveness::Green);
    $device = createMapSpoke($owner, null, now(), SpokeLiveness::Green, deviceName: 'Desk');
    $otherOwner = createMapSpoke($other, null, now(), SpokeLiveness::Green, deviceName: 'Desk');
    $unnamed = createMapSpoke($owner, null, now(), SpokeLiveness::Green);

    $spokes = Livewire::actingAs($owner)->test(SpokeMap::class)->viewData('spokes')->keyBy('id');

    expect($spokes[$custom->id]['name'])->toBe("Desk (#{$custom->id})")
        ->and($spokes[$device->id]['name'])->toBe("Desk (#{$device->id})")
        ->and($spokes[$otherOwner->id]['name'])->toBe('Desk')
        ->and($spokes[$unnamed->id]['name'])->toBe("Spoke #{$unnamed->id}");
});

test('an owner can rename and clear a spoke name inline', function (): void {
    $owner = capstanUser();
    $spoke = createMapSpoke($owner, null, now(), SpokeLiveness::Green, deviceName: 'Owner Laptop');
    $component = Livewire::actingAs($owner)->test(SpokeMap::class);

    $component->call('beginRenaming', $spoke->id)
        ->assertSet('renamingSpokeId', $spoke->id)
        ->set('spokeName', 'Release runner')
        ->call('renameSpoke', $spoke->id)
        ->assertSee('Release runner');
    expect($spoke->refresh()->name)->toBe('Release runner');

    $component->call('beginRenaming', $spoke->id)
        ->set('spokeName', '   ')
        ->call('renameSpoke', $spoke->id)
        ->assertSee('Owner Laptop');
    expect($spoke->refresh()->name)->toBeNull();
});

test('a tampered rename call cannot change another users spoke', function (): void {
    $admin = capstanUser(['role' => UserRole::Admin->value]);
    $owner = capstanUser();
    $spoke = createMapSpoke($owner, 'Original name', now(), SpokeLiveness::Green);

    Livewire::actingAs($admin)
        ->test(SpokeMap::class)
        ->set('spokeName', 'Tampered name')
        ->call('renameSpoke', $spoke->id)
        ->assertForbidden();

    expect($spoke->refresh()->name)->toBe('Original name');
});

test('a tampered retirement is forbidden through Livewire and the HTTP route', function (): void {
    $owner = capstanUser();
    $other = capstanUser();
    $spoke = createMapSpoke($owner, 'Owned spoke', now(), SpokeLiveness::Green);

    Livewire::actingAs($other)
        ->test(SpokeMap::class)
        ->call('retireSpoke', $spoke->id)
        ->assertForbidden();

    $this->actingAsVersioned($other)
        ->post(route('postmaster.spokes.retire', $spoke))
        ->assertForbidden();

    expect($spoke->refresh()->retired_at)->toBeNull();
});

test('retirement refuses a live Work lease then hides the spoke revokes its credential and preserves history', function (): void {
    $owner = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $owner->id,
        'credential_id' => $issued['credential']->id,
        'last_polled_at' => now(),
        'probe_status' => SpokeLiveness::Green,
    ]);
    $inbox = Inbox::query()->create(['actor_id' => (string) $owner->id, 'local_part' => 'retire-history']);
    $spoke->inboxes()->attach($inbox);
    $task = WorkTask::query()->create([
        'team_id' => Team::query()->where('is_default', true)->sole()->id,
        'title' => 'Retirement history task',
        'failover' => true,
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => (string) $owner->id,
    ]);
    $item = WorkItem::query()->create([
        'work_task_id' => $task->id,
        'role' => 'builder',
        'state' => 'claimed',
        'claimant_spoke_id' => $spoke->id,
        'claimant_actor' => (string) $owner->id,
        'lease_until' => now()->addMinute(),
        'claimed_at' => now(),
        'fence' => 1,
    ]);
    $milestone = WorkMilestone::query()->create([
        'work_item_id' => $item->id,
        'fence' => 1,
        'kind' => 'note',
        'payload' => ['summary' => 'History remains readable'],
        'created_at' => now(),
    ]);

    Livewire::actingAs($owner)
        ->test(SpokeMap::class)
        ->call('retireSpoke', $spoke->id)
        ->assertHasErrors('retireSpoke')
        ->assertSee('live Work lease');
    expect($spoke->refresh()->retired_at)->toBeNull()
        ->and($issued['credential']->refresh()->revoked_at)->toBeNull();

    $item->forceFill(['lease_until' => now()->subSecond()])->save();
    Livewire::actingAs($owner)
        ->test(SpokeMap::class)
        ->call('retireSpoke', $spoke->id)
        ->assertHasNoErrors()
        ->assertDontSee('Retirement history task');

    expect($spoke->refresh()->retired_at)->not->toBeNull()
        ->and($spoke->retired_by)->toBe((string) $owner->id)
        ->and($issued['credential']->refresh()->revoked_at)->not->toBeNull()
        ->and(DB::table('spoke_inboxes')->where('spoke_id', $spoke->id)->exists())->toBeFalse()
        ->and(Spoke::query()->find($spoke->id))->not->toBeNull()
        ->and(WorkItem::query()->find($item->id)?->claimant_spoke_id)->toBe($spoke->id)
        ->and(WorkMilestone::query()->find($milestone->id)?->payload)->toBe(['summary' => 'History remains readable'])
        ->and(Livewire::actingAs($owner)->test(SpokeMap::class)->viewData('spokes'))->toBeEmpty();
});

test('retirement refuses an interactive seat lease with no active Work item', function (): void {
    $owner = capstanUser();
    $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $owner->id,
        'credential_id' => $issued['credential']->id,
    ]);
    $task = WorkTask::query()->create([
        'team_id' => Team::query()->where('is_default', true)->sole()->id,
        'title' => 'Interactive seat retirement guard',
        'failover' => true,
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => (string) $owner->id,
    ]);
    $seat = WorkOrchestratorSeat::query()->create([
        'work_task_id' => $task->id,
        'holder_actor' => (string) $owner->id,
        'holder_spoke_id' => $spoke->id,
        'session_ref' => 'interactive-seat',
        'interactive' => true,
        'lease_until' => now()->addMinute(),
        'fence' => 1,
    ]);

    Livewire::actingAs($owner)
        ->test(SpokeMap::class)
        ->call('retireSpoke', $spoke->id)
        ->assertHasErrors('retireSpoke')
        ->assertSee('live Work lease');
    expect($spoke->refresh()->retired_at)->toBeNull()
        ->and($issued['credential']->refresh()->revoked_at)->toBeNull()
        ->and($seat->fresh()?->active_item_id)->toBeNull();

    $seat->forceFill(['lease_until' => now()->subSecond()])->save();
    Livewire::actingAs($owner)->test(SpokeMap::class)
        ->call('retireSpoke', $spoke->id)
        ->assertHasNoErrors();
    expect($spoke->refresh()->retired_at)->not->toBeNull();
});

test('device names are escaped in the map', function (): void {
    $owner = capstanUser();
    createMapSpoke($owner, null, now(), SpokeLiveness::Green, deviceName: '<script>alert("map")</script>');

    $html = Livewire::actingAs($owner)->test(SpokeMap::class)->html();

    expect($html)->toContain('&lt;script&gt;alert(&quot;map&quot;)&lt;/script&gt;')
        ->not->toContain('<script>alert("map")</script>');
});

test('map status combines poll recency and probe state', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $green = createMapSpoke($owner, 'green-spoke', now()->subSecond(), SpokeLiveness::Green);
    $stale = createMapSpoke($owner, 'stale-spoke', now()->subSeconds(301), SpokeLiveness::Green);
    $failed = createMapSpoke($owner, 'failed-spoke', now()->subSecond(), SpokeLiveness::Red);
    $pending = createMapSpoke($owner, 'pending-spoke', now()->subSecond(), SpokeLiveness::Unknown);
    $neverPolled = createMapSpoke($owner, 'never-polled-spoke', null, SpokeLiveness::Green);

    $component = Livewire::actingAs($owner)->test(SpokeMap::class);
    $states = $component->viewData('spokes')->mapWithKeys(
        fn (array $spoke): array => [$spoke['id'] => $spoke['status']],
    );

    expect($states[$green->id])->toBe(SpokeMapStatus::Green->value)
        ->and($states[$stale->id])->toBe(SpokeMapStatus::Red->value)
        ->and($states[$failed->id])->toBe(SpokeMapStatus::Red->value)
        ->and($states[$pending->id])->toBe(SpokeMapStatus::Pending->value)
        ->and($states[$neverPolled->id])->toBe(SpokeMapStatus::Red->value)
        ->and(substr_count($component->html(), 'data-status="green"'))->toBe(1)
        ->and(substr_count($component->html(), 'data-status="red"'))->toBe(3)
        ->and(substr_count($component->html(), 'data-status="pending"'))->toBe(1);
});

test('the configured staleness boundary is honored', function (): void {
    config(['capstan.postmaster.map.stale_after_seconds' => 120]);
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $inside = createMapSpoke($owner, 'inside-window', now()->subSeconds(119), SpokeLiveness::Green);
    $atCutoff = createMapSpoke($owner, 'at-cutoff', now()->subSeconds(120), SpokeLiveness::Green);
    $outside = createMapSpoke($owner, 'outside-window', now()->subSeconds(121), SpokeLiveness::Green);

    $states = Livewire::actingAs($owner)
        ->test(SpokeMap::class)
        ->viewData('spokes')
        ->mapWithKeys(fn (array $spoke): array => [$spoke['id'] => $spoke['status']]);

    expect($states[$inside->id])->toBe(SpokeMapStatus::Green->value)
        ->and($states[$atCutoff->id])->toBe(SpokeMapStatus::Green->value)
        ->and($states[$outside->id])->toBe(SpokeMapStatus::Red->value);
});

test('the staleness window has a safe default and minimum', function (array $mapConfig, int $insideAge, int $outsideAge): void {
    config(['capstan.postmaster.map' => $mapConfig]);
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $inside = createMapSpoke($owner, 'safe-window-inside', now()->subSeconds($insideAge), SpokeLiveness::Green);
    $outside = createMapSpoke($owner, 'safe-window-outside', now()->subSeconds($outsideAge), SpokeLiveness::Green);

    $states = Livewire::actingAs($owner)
        ->test(SpokeMap::class)
        ->viewData('spokes')
        ->mapWithKeys(fn (array $spoke): array => [$spoke['id'] => $spoke['status']]);

    expect($states[$inside->id])->toBe(SpokeMapStatus::Green->value)
        ->and($states[$outside->id])->toBe(SpokeMapStatus::Red->value);
})->with([
    'zero is clamped to sixty seconds' => [['stale_after_seconds' => 0], 59, 61],
    'missing uses the default' => [[], 299, 301],
]);

test('every package role sees the same installation-wide spoke map', function (UserRole $role): void {
    $operator = capstanUser(['role' => $role->value]);
    $firstMember = capstanUser();
    $secondMember = capstanUser();
    $first = createMapSpoke($firstMember, 'first-member-spoke', now(), SpokeLiveness::Green);
    $second = createMapSpoke($secondMember, 'second-member-spoke', now(), SpokeLiveness::Unknown);

    $visibleIds = Livewire::actingAs($operator)
        ->test(SpokeMap::class)
        ->viewData('spokes')
        ->pluck('id')
        ->all();

    expect($visibleIds)->toHaveCount(2)
        ->and($visibleIds)->toContain($first->id, $second->id);
})->with([
    'owner' => UserRole::Owner,
    'admin' => UserRole::Admin,
    'member' => UserRole::Member,
]);

test('the postmaster map is available without a product feature gate and does not change routing state', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $spoke = createMapSpoke($owner, 'disabled-map-spoke', now(), SpokeLiveness::Green, 2);
    $before = [
        Spoke::query()->count(),
        Inbox::query()->count(),
        DB::table('spoke_inboxes')->count(),
    ];
    $this->actingAsVersioned($owner)->get(route('postmaster.map'))->assertOk();

    expect([
        Spoke::query()->count(),
        Inbox::query()->count(),
        DB::table('spoke_inboxes')->count(),
    ])->toBe($before)
        ->and($spoke->fresh())->not->toBeNull();
});

test('an existing Livewire snapshot remains available', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $spoke = createMapSpoke($owner, 'snapshot-guarded-spoke', now(), SpokeLiveness::Green, 2);
    $page = $this->actingAsVersioned($owner)
        ->get(route('postmaster.map'))
        ->assertOk()
        ->assertSee($spoke->name);
    $snapshot = htmlspecialchars_decode(
        (string) str($page->getContent())->betweenFirst('wire:snapshot="', '"'),
        ENT_QUOTES | ENT_SUBSTITUTE,
    );
    $this->withHeader('X-Livewire', '1')
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [],
            ]],
        ])
        ->assertOk();
});

test('an unauthenticated map request redirects to login', function (): void {
    $this->get(route('postmaster.map'))->assertRedirect(route('bfc.login', ['intended' => '/postmaster']));
});

test('rendering several routed spokes uses a bounded query count', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);

    foreach (range(1, 6) as $index) {
        createMapSpoke($owner, "bounded-spoke-{$index}", now(), SpokeLiveness::Green, 2);
    }

    $queryCount = 0;
    DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $queryCount++;
        }
    });

    $spokes = Livewire::actingAs($owner)
        ->test(SpokeMap::class)
        ->viewData('spokes');

    expect($spokes)->toHaveCount(6)
        ->and($spokes->every(fn (array $spoke): bool => $spoke['inboxes_count'] === 2))->toBeTrue()
        ->and($queryCount)->toBeLessThanOrEqual(12);
});

test('machine owners and managers edit only the selected team Work profile without exposing credential fields', function (): void {
    $owner = capstanUser(['role' => UserRole::Member->value]);
    $machine = createMapSpoke($owner, 'profile-machine', now(), SpokeLiveness::Green, 0);
    $defaultTeam = Team::default();
    $selectedTeam = Team::query()->create(['name' => 'Selected Work team', 'slug' => 'selected-work-team']);
    DB::table('team_user')->insert([
        'team_id' => $selectedTeam->id,
        'user_id' => $owner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $defaultSetting = MachineTeamWorkSetting::query()->create([
        'spoke_id' => $machine->id,
        'team_id' => $defaultTeam->id,
        'enabled' => true,
        'roles' => ['builder'],
        'capabilities' => [],
        'role_providers' => [],
        'concurrency_ceiling' => 1,
        'updated_by_actor' => (string) $owner->id,
    ]);
    $selectedSetting = MachineTeamWorkSetting::query()->create([
        'spoke_id' => $machine->id,
        'team_id' => $selectedTeam->id,
        'enabled' => false,
        'roles' => ['reviewer'],
        'capabilities' => [],
        'role_providers' => [],
        'concurrency_ceiling' => 1,
        'updated_by_actor' => (string) $owner->id,
    ]);

    Livewire::actingAs($owner)->test(SpokeMap::class)
        ->assertSeeHtml('data-testid="machine-team-selector"')
        ->set('selectedTeamId', $selectedTeam->id)
        ->call('beginEditingWorkProfile', $machine->id)
        ->assertSeeHtml('data-testid="machine-work-profile-form"')
        ->assertSet('workEnabled', false)
        ->assertSet('workRoles', 'reviewer')
        ->set('workEnabled', true)
        ->set('workRoles', 'builder, reviewer')
        ->set('workCapabilities', 'php')
        ->set('workRoleProviders', '{"builder":"codex"}')
        ->set('workConcurrencyCeiling', 2)
        ->call('saveWorkProfile', $machine->id)
        ->assertHasNoErrors();

    $selectedSetting->refresh();
    $defaultSetting->refresh();
    expect($selectedSetting->roles)->toBe(['builder', 'reviewer'])
        ->and($selectedSetting->capabilities)->toBe(['php'])
        ->and($selectedSetting->role_providers)->toBe(['builder' => 'codex'])
        ->and($selectedSetting->concurrency_ceiling)->toBe(2)
        ->and($defaultSetting->roles)->toBe(['builder'])
        ->and($defaultSetting->enabled)->toBeTrue()
        ->and(MachineWorkSettingChange::query()->count())->toBe(1);

    $admin = capstanUser(['role' => UserRole::Admin->value]);
    DB::table('team_user')->insert([
        'team_id' => $selectedTeam->id,
        'user_id' => $admin->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Livewire::actingAs($admin)->test(SpokeMap::class)
        ->set('selectedTeamId', $selectedTeam->id)
        ->call('beginEditingWorkProfile', $machine->id)
        ->set('workEnabled', false)
        ->call('saveWorkProfile', $machine->id)
        ->assertHasNoErrors();
    expect($selectedSetting->refresh()->enabled)->toBeFalse()
        ->and($defaultSetting->refresh()->enabled)->toBeTrue()
        ->and(MachineWorkSettingChange::query()->count())->toBe(2);

    $member = capstanUser(['role' => UserRole::Member->value]);
    DB::table('team_user')->insert([
        'team_id' => $selectedTeam->id,
        'user_id' => $member->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Livewire::actingAs($member)->test(SpokeMap::class)
        ->set('selectedTeamId', $selectedTeam->id)
        ->call('beginEditingWorkProfile', $machine->id)
        ->assertForbidden();

    $html = $this->actingAsVersioned($owner)->get(route('postmaster.map'))->assertOk()->content();
    expect($html)->not->toContain($machine->credential_id);
});

test('work profile actions reject a tampered team and a team manager attaching an unrelated machine', function (): void {
    $owner = capstanUser(['role' => UserRole::Member->value]);
    $machine = createMapSpoke($owner, 'owned machine', now(), SpokeLiveness::Green);
    $foreignTeam = Team::query()->create(['name' => 'Foreign team', 'slug' => 'foreign-work-profile']);

    Livewire::actingAs($owner)->test(SpokeMap::class)
        ->call('beginEditingWorkProfile', $machine->id)
        ->set('editingWorkTeamId', $foreignTeam->id)
        ->call('saveWorkProfile', $machine->id)
        ->assertStatus(409);
    expect(MachineTeamWorkSetting::query()->where('spoke_id', $machine->id)->where('team_id', $foreignTeam->id)->doesntExist())->toBeTrue();

    $admin = capstanUser(['role' => UserRole::Admin->value]);
    DB::table('team_user')->insert([
        'team_id' => $foreignTeam->id,
        'user_id' => $admin->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Livewire::actingAs($admin)->test(SpokeMap::class)
        ->set('selectedTeamId', $foreignTeam->id)
        ->set('editingWorkSpokeId', $machine->id)
        ->set('editingWorkTeamId', $foreignTeam->id)
        ->set('workRoleProviders', '{}')
        ->call('saveWorkProfile', $machine->id)
        ->assertHasErrors('workProfile');
    expect(MachineTeamWorkSetting::query()->where('spoke_id', $machine->id)->where('team_id', $foreignTeam->id)->doesntExist())->toBeTrue();
});

test('a selected-team manager creates the first profile for a same-team machine', function (): void {
    $team = Team::query()->create(['name' => 'Non-default Work team', 'slug' => 'non-default-work-team']);
    $owner = capstanUser(['role' => UserRole::Member->value]);
    $manager = capstanUser(['role' => UserRole::Admin->value]);
    foreach ([$owner, $manager] as $member) {
        DB::table('team_user')->insert([
            'team_id' => $team->id,
            'user_id' => $member->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $machine = createMapSpoke($owner, 'unconfigured team machine', now(), SpokeLiveness::Green);

    $component = Livewire::actingAs($manager)->test(SpokeMap::class)
        ->set('selectedTeamId', $team->id);
    $budget = collect($component->viewData('machineBudgets')['machines'])->firstWhere('machine_id', $machine->id);
    expect($budget)->not->toBeNull()
        ->and($budget['can_manage_work'])->toBeTrue();

    $component
        ->call('beginEditingWorkProfile', $machine->id)
        ->assertSet('editingWorkSpokeId', $machine->id)
        ->set('workEnabled', true)
        ->set('workRoles', 'builder')
        ->set('workCapabilities', '')
        ->set('workRoleProviders', '{}')
        ->set('workConcurrencyCeiling', 1)
        ->call('saveWorkProfile', $machine->id)
        ->assertHasNoErrors();

    $setting = MachineTeamWorkSetting::query()
        ->where('spoke_id', $machine->id)
        ->where('team_id', $team->id)
        ->sole();
    expect($setting->roles)->toBe(['builder'])
        ->and($setting->updated_by_actor)->toBe((string) $manager->id);
});

test('map ordering stays in id order after rename and status changes', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $alpha = createMapSpoke($owner, 'alpha', now(), SpokeLiveness::Green);
    $bravo = createMapSpoke($owner, 'bravo', now(), SpokeLiveness::Green);
    $charlie = createMapSpoke($owner, 'charlie', now(), SpokeLiveness::Green);
    $expected = [$alpha->id, $bravo->id, $charlie->id];
    $before = Livewire::actingAs($owner)->test(SpokeMap::class)->viewData('spokes')->pluck('id')->all();

    Livewire::actingAs($owner)
        ->test(SpokeMap::class)
        ->set('spokeName', 'zulu')
        ->call('renameSpoke', $alpha->id)
        ->assertHasNoErrors();
    $afterRename = Livewire::actingAs($owner)->test(SpokeMap::class)->viewData('spokes')->pluck('id')->all();

    $charlie->forceFill(['probe_status' => SpokeLiveness::Red])->save();
    $afterStatusChange = Livewire::actingAs($owner)->test(SpokeMap::class)->viewData('spokes')->pluck('id')->all();

    expect($before)->toBe($expected)
        ->and($afterRename)->toBe($expected)
        ->and($afterStatusChange)->toBe($expected);
});
