<?php

use App\Livewire\Postmaster\SpokeMap;
use App\Machine\MachineBudgetProjection;
use App\Machine\MachineWorkProfiles;
use App\Models\MachineTeamWorkSetting;
use App\Models\MachineUsageReport;
use App\Models\Spoke;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    $legacy = Spoke::query()->create([
        'actor_id' => (string) $owner->id,
        'credential_id' => (string) Str::uuid(),
        'credential_backed' => true,
        'name' => 'Legacy machine',
    ]);
    $synthetic = Spoke::query()->create([
        'actor_id' => (string) $owner->id,
        'credential_id' => (string) Str::uuid(),
        'credential_backed' => false,
        'name' => 'Synthetic delegated spoke',
    ]);
    budgetUsage($first, 'codex', 70, now()->addHours(4)->toJSON(), [[
        'slot' => 'shorter', 'kind' => 'consumption', 'window_seconds' => 3600,
        'remaining_percent' => 90, 'resets_at' => now()->addHour()->toJSON(),
    ]]);
    budgetUsage($second, 'codex', 80, now()->addHours(5)->toJSON());

    $projection = resolve(MachineBudgetProjection::class)->forTeam($viewer, $team->id, now());
    $web = Livewire::actingAs($viewer)->test(SpokeMap::class)->viewData('machineBudgets');
    $encoded = json_encode($projection, JSON_THROW_ON_ERROR);

    expect($web)->toBe($projection)
        ->and(array_column($projection['machines'], 'name'))->toBe(['First', 'Second', 'Orphan', 'Legacy machine'])
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
        ->and($encoded)->not->toContain('Foreign machine')
        ->and($encoded)->not->toContain('Synthetic delegated spoke')
        ->and($projection['machines'][3]['machine_id'])->toBe($legacy->id)
        ->and(collect($projection['machines'])->pluck('machine_id'))->not->toContain($synthetic->id);
});

test('membership is checked before projection and org administrators receive no bypass', function (): void {
    $admin = capstanUser(['role' => UserRole::Admin->value]);
    $team = Team::query()->create(['name' => 'Unshared', 'slug' => 'unshared-budget']);

    expect(fn () => resolve(MachineBudgetProjection::class)->forTeam($admin, $team->id, now()))
        ->toThrow(AuthorizationException::class);
});

test('projection and writer share the selected-team Work-profile permission', function (): void {
    $team = Team::default();
    $manager = capstanUser(['role' => UserRole::Admin->value]);
    $sameTeamOwner = capstanUser(['role' => UserRole::Member->value]);
    $foreignOwner = capstanUser(['role' => UserRole::Member->value]);
    DB::table('team_user')->where('team_id', $team->id)->where('user_id', $foreignOwner->id)->delete();
    $sameTeamMachine = Spoke::query()->create([
        'actor_id' => (string) $sameTeamOwner->id,
        'credential_id' => (string) Str::uuid(),
        'credential_backed' => true,
        'name' => 'Same-team unconfigured machine',
    ]);
    $foreignMachine = Spoke::query()->create([
        'actor_id' => (string) $foreignOwner->id,
        'credential_id' => (string) Str::uuid(),
        'credential_backed' => true,
        'name' => 'Foreign unconfigured machine',
    ]);
    $profile = [
        'enabled' => true,
        'roles' => ['builder'],
        'capabilities' => [],
        'role_providers' => [],
        'concurrency_ceiling' => 1,
    ];

    $projection = resolve(MachineBudgetProjection::class)->forTeam($manager, $team->id, now());
    $permissions = collect($projection['machines'])->keyBy('machine_id')->map->can_manage_work;
    expect($permissions[$sameTeamMachine->id])->toBeTrue()
        ->and($permissions[$foreignMachine->id])->toBeFalse();

    $profiles = resolve(MachineWorkProfiles::class);
    expect($profiles->put($sameTeamMachine, $team, $manager, $profile, now()))->toBeTrue();
    expect(fn () => $profiles->put($foreignMachine, $team, $manager, $profile, now()))
        ->toThrow(AuthorizationException::class);

    $otherTeamManager = capstanUser(['role' => UserRole::Owner->value]);
    DB::table('team_user')->where('team_id', $team->id)->where('user_id', $otherTeamManager->id)->delete();
    expect(fn () => $profiles->put($sameTeamMachine, $team, $otherTeamManager, $profile, now()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => resolve(MachineBudgetProjection::class)->forTeam($otherTeamManager, $team->id, now()))
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

test('team membership migration backfills in the database and provides the user-first index', function (): void {
    $default = DB::getDefaultConnection();
    config(['database.connections.team_membership_migration' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('team_membership_migration');

    try {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_default')->default(false);
        });
        Schema::create('users', fn (Blueprint $table) => $table->id());
        DB::table('teams')->insert(['id' => 7, 'is_default' => true]);
        DB::table('users')->insert([['id' => 10], ['id' => 20], ['id' => 30]]);

        $migration = require database_path('migrations/2026_10_20_015000_create_team_user_table.php');
        $migration->up();
        expect(DB::table('team_user')->orderBy('user_id')->get(['team_id', 'user_id'])->map(fn ($row): array => (array) $row)->all())->toBe([
            ['team_id' => 7, 'user_id' => 10],
            ['team_id' => 7, 'user_id' => 20],
            ['team_id' => 7, 'user_id' => 30],
        ])->and(collect(Schema::getIndexes('team_user'))->pluck('name'))->toContain('team_user_user_team_index');

        $migration->down();
        expect(Schema::hasTable('team_user'))->toBeFalse();
    } finally {
        DB::disconnect('team_membership_migration');
        DB::setDefaultConnection($default);
    }
});
