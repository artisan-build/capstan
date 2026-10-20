<?php

use App\Livewire\Postmaster\SpokeMap;
use App\Machine\MachineBudgetProjection;
use App\Models\MachineTeamWorkSetting;
use App\Models\MachineUsageReport;
use App\Models\Spoke;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('b', 32)),
        'capstan.features.postmaster' => true,
    ]);
    Feature::flushCache();
    Date::setTestNow('2026-10-20 12:00:00');
});

afterEach(fn () => Date::setTestNow());

function budgetMachine(User $owner, Team $team, string $name, array $providers = [], array $spoke = []): Spoke
{
    $machine = Spoke::query()->create(array_replace([
        'actor_id' => (string) $owner->id,
        'credential_id' => (string) Str::uuid(),
        'name' => $name,
        'last_polled_at' => now(),
        'probe_status' => 'green',
    ], $spoke));
    MachineTeamWorkSetting::query()->create([
        'spoke_id' => $machine->id,
        'team_id' => $team->id,
        'enabled' => true,
        'roles' => array_keys($providers),
        'capabilities' => [],
        'role_providers' => $providers,
        'concurrency_ceiling' => 1,
        'updated_by_actor' => (string) $owner->id,
    ]);

    return $machine;
}

function budgetUsage(Spoke $machine, string $provider, float $remaining, string $reset, array $extraWindows = []): void
{
    $report = MachineUsageReport::query()->create([
        'spoke_id' => $machine->id,
        'source' => 'openusage-app',
        'availability' => 'available',
        'collected_at' => now(),
        'last_attempt_at' => now(),
        'last_success_received_at' => now(),
    ]);
    $usageProvider = $report->providers()->create([
        'provider' => $provider,
        'plan' => 'pro',
        'fetched_at' => now(),
        'source_stale' => false,
        'expires_at' => now()->addMinutes(5),
        'status' => 'ok',
    ]);
    foreach ([[
        'slot' => 'primary', 'kind' => 'consumption', 'window_seconds' => 604800,
        'remaining_percent' => $remaining, 'resets_at' => $reset,
    ], ...$extraWindows] as $window) {
        $usageProvider->windows()->create($window);
    }
}

test('one team-scoped projection drives web budgets without cross-team or email disclosure', function (): void {
    $viewer = capstanUser(['name' => 'Viewer', 'role' => UserRole::Member->value]);
    $owner = capstanUser(['name' => 'Same Name', 'email' => 'private-owner@example.test']);
    $otherOwner = capstanUser(['name' => 'Same Name', 'email' => 'private-other@example.test']);
    $team = Team::default();
    $foreignTeam = Team::query()->create(['name' => 'Foreign', 'slug' => 'foreign-budget']);
    $first = budgetMachine($owner, $team, 'First', ['builder' => 'codex']);
    $second = budgetMachine($owner, $team, 'Second', ['builder' => 'codex']);
    $orphan = budgetMachine($otherOwner, $team, 'Orphan', ['builder' => 'codex'], ['orphaned_at' => now()]);
    budgetMachine($owner, $foreignTeam, 'Foreign machine', ['builder' => 'claude']);
    budgetUsage($first, 'codex', 70, now()->addHours(4)->toJSON(), [[
        'slot' => 'shorter', 'kind' => 'consumption', 'window_seconds' => 3600,
        'remaining_percent' => 90, 'resets_at' => now()->addHour()->toJSON(),
    ]]);
    budgetUsage($second, 'codex', 80, now()->addHours(5)->toJSON());

    $projection = resolve(MachineBudgetProjection::class)->forTeam($viewer, $team->id, now());
    $web = Livewire::actingAs($viewer)->test(SpokeMap::class)->viewData('machineBudgets');
    $encoded = json_encode($projection, JSON_THROW_ON_ERROR);

    expect($web)->toBe($projection)
        ->and(array_column($projection['machines'], 'name'))->toBe(['First', 'Second', 'Orphan'])
        ->and(data_get($projection, 'machines.2.reactivation_required'))->toBeTrue()
        ->and($projection['people'])->toHaveCount(2)
        ->and(data_get($projection, 'people.0.providers.0.machines'))->toHaveCount(2)
        ->and(data_get($projection, 'people.0.providers.0.best_fresh_remaining_percent'))->toBe(80.0)
        ->and(data_get($projection, 'people.0.providers.0.earliest_corresponding_reset_at'))->toBe(now()->addHours(5)->toJSON())
        ->and(data_get($projection, 'machines.0.usage.providers.0.resets_at'))->toBe(now()->addHours(4)->toJSON())
        ->and(data_get($projection, 'machines.0.usage.providers.0.windows'))->toHaveCount(2)
        ->and($encoded)->not->toContain('private-owner@example.test')
        ->and($encoded)->not->toContain('private-other@example.test')
        ->and($encoded)->not->toContain($first->credential_id)
        ->and($encoded)->not->toContain('Foreign machine');
});

test('membership is checked before projection and org administrators receive no bypass', function (): void {
    $admin = capstanUser(['role' => UserRole::Admin->value]);
    $team = Team::query()->create(['name' => 'Unshared', 'slug' => 'unshared-budget']);

    expect(fn () => resolve(MachineBudgetProjection::class)->forTeam($admin, $team->id, now()))
        ->toThrow(AuthorizationException::class);
});

test('the projection marks true overflow and accepts Codex without a five-hour window', function (): void {
    $viewer = capstanUser();
    $team = Team::default();
    foreach (range(1, MachineBudgetProjection::MAX_MACHINES + 1) as $index) {
        budgetMachine($viewer, $team, 'Machine '.$index, ['builder' => 'codex']);
    }
    $first = Spoke::query()->oldest('id')->firstOrFail();
    budgetUsage($first, 'codex', 65, now()->addWeek()->toJSON());

    $projection = resolve(MachineBudgetProjection::class)->forTeam($viewer, $team->id, now());
    expect($projection['machines'])->toHaveCount(MachineBudgetProjection::MAX_MACHINES)
        ->and(data_get($projection, 'bounds.truncated'))->toBeTrue()
        ->and(data_get($projection, 'machines.0.usage.providers.0.windows.0.window_seconds'))->toBe(604800)
        ->and(json_encode($projection, JSON_THROW_ON_ERROR))->not->toContain('18000');
});

test('inactive users are denied before machine representation', function (): void {
    $viewer = capstanUser();
    $viewer->forceFill(['status' => 'inactive'])->save();

    expect(fn () => resolve(MachineBudgetProjection::class)->forTeam($viewer, Team::default()->id, now()))
        ->toThrow(AuthorizationException::class);
});
