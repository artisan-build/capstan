<?php

use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Livewire\Postmaster\SpokeMap;
use App\Models\Inbox;
use App\Models\Spoke;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('m', 32)),
        'capstan.features.postmaster' => true,
        'capstan.postmaster.map.stale_after_seconds' => 300,
    ]);
    Feature::flushCache();
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

test('map owner names use display name then email without exposing actor ids', function (): void {
    $viewer = capstanUser(['role' => UserRole::Owner->value]);
    $named = capstanUser(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    $emailOnly = capstanUser(['name' => '  ', 'email' => 'email-only@example.test']);
    $missing = capstanUser();
    $namedSpoke = createMapSpoke($named, 'named-owner', now(), SpokeLiveness::Green);
    $emailSpoke = createMapSpoke($emailOnly, 'email-owner', now(), SpokeLiveness::Green);
    $missingSpoke = createMapSpoke($missing, 'missing-owner', now(), SpokeLiveness::Green);
    $missingId = (string) $missing->id;
    $missing->delete();

    $spokes = Livewire::actingAs($viewer)->test(SpokeMap::class)->viewData('spokes')->keyBy('id');

    expect($spokes[$namedSpoke->id]['owner_name'])->toBe('Ada Lovelace')
        ->and($spokes[$emailSpoke->id]['owner_name'])->toBe('email-only@example.test')
        ->and($spokes[$missingSpoke->id]['owner_name'])->toBe('Unknown user')
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

    expect($states[$green->id])->toBe(SpokeMapStatus::Green)
        ->and($states[$stale->id])->toBe(SpokeMapStatus::Red)
        ->and($states[$failed->id])->toBe(SpokeMapStatus::Red)
        ->and($states[$pending->id])->toBe(SpokeMapStatus::Pending)
        ->and($states[$neverPolled->id])->toBe(SpokeMapStatus::Red)
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

    expect($states[$inside->id])->toBe(SpokeMapStatus::Green)
        ->and($states[$atCutoff->id])->toBe(SpokeMapStatus::Green)
        ->and($states[$outside->id])->toBe(SpokeMapStatus::Red);
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

    expect($states[$inside->id])->toBe(SpokeMapStatus::Green)
        ->and($states[$outside->id])->toBe(SpokeMapStatus::Red);
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

test('a disabled postmaster map returns 404 without changing routing state', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $spoke = createMapSpoke($owner, 'disabled-map-spoke', now(), SpokeLiveness::Green, 2);
    $before = [
        Spoke::query()->count(),
        Inbox::query()->count(),
        DB::table('spoke_inboxes')->count(),
    ];
    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();

    $this->actingAsVersioned($owner)->get(route('postmaster.map'))->assertNotFound();

    expect([
        Spoke::query()->count(),
        Inbox::query()->count(),
        DB::table('spoke_inboxes')->count(),
    ])->toBe($before)
        ->and($spoke->fresh())->not->toBeNull();
});

test('disabling postmaster rejects an existing Livewire snapshot', function (): void {
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
    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();

    $this->withHeader('X-Livewire', '1')
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [],
            ]],
        ])
        ->assertNotFound()
        ->assertDontSee($spoke->name);
});

test('an unauthenticated map request redirects to login', function (): void {
    $this->get(route('postmaster.map'))->assertRedirect(route('bfc.login'));
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
        ->and($queryCount)->toBeLessThanOrEqual(3);
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
