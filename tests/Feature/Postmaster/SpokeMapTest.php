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
    string $name,
    ?CarbonInterface $lastPolledAt,
    SpokeLiveness $probeStatus,
    int $inboxes = 0,
): Spoke {
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $user->id,
        'credential_id' => (string) Str::uuid(),
        'name' => $name,
        'last_polled_at' => $lastPolledAt,
        'probe_status' => $probeStatus,
    ]);

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
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $spoke = createMapSpoke($owner, 'rendered-spoke', now(), SpokeLiveness::Green, 2);

    $this->actingAsVersioned($owner)
        ->get(route('postmaster.map'))
        ->assertOk()
        ->assertSee($spoke->name)
        ->assertSeeHtml('wire:poll.30s')
        ->assertSeeHtml('data-status="green"')
        ->assertSeeHtml('data-inbox-count="2"');
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

test('map ordering is deterministic with red spokes first', function (): void {
    $owner = capstanUser(['role' => UserRole::Owner->value]);
    $redZulu = createMapSpoke($owner, 'zulu-red', now(), SpokeLiveness::Red);
    $pendingAlpha = createMapSpoke($owner, 'alpha-pending', now(), SpokeLiveness::Unknown);
    $greenGamma = createMapSpoke($owner, 'gamma-green', now(), SpokeLiveness::Green);
    $redBeta = createMapSpoke($owner, 'beta-red', now()->subSeconds(301), SpokeLiveness::Green);
    $expected = [$redBeta->id, $redZulu->id, $pendingAlpha->id, $greenGamma->id];

    $first = Livewire::actingAs($owner)->test(SpokeMap::class)->viewData('spokes')->pluck('id')->all();
    $second = Livewire::actingAs($owner)->test(SpokeMap::class)->viewData('spokes')->pluck('id')->all();

    expect($first)->toBe($expected)
        ->and($second)->toBe($expected);
});
