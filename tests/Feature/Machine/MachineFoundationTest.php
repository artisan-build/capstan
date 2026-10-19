<?php

use App\Models\MachineTeamWorkSetting;
use App\Models\MachineUsageProvider;
use App\Models\MachineUsageReport;
use App\Models\MachineUsageWindow;
use App\Models\MachineWorkPresence;
use App\Models\MachineWorkSettingChange;
use App\Models\Spoke;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('machine foundation schema exposes bounded lifecycle usage settings audit and presence carriers', function (): void {
    expect(Schema::hasColumns('spokes', ['inactive_at', 'orphaned_at']))->toBeTrue()
        ->and(Schema::hasColumns('machine_usage_reports', [
            'spoke_id', 'source', 'source_version', 'availability', 'collected_at',
            'last_attempt_at', 'last_success_received_at', 'failure_code',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('machine_usage_providers', [
            'machine_usage_report_id', 'provider', 'plan', 'fetched_at',
            'source_stale', 'expires_at', 'status',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('machine_usage_windows', [
            'machine_usage_provider_id', 'slot', 'kind', 'window_seconds',
            'remaining_percent', 'resets_at', 'window_identity',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('machine_team_work_settings', [
            'spoke_id', 'team_id', 'enabled', 'roles', 'capabilities',
            'role_providers', 'concurrency_ceiling', 'updated_by_actor',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('machine_work_setting_changes', [
            'spoke_id', 'team_id', 'old_enabled', 'old_roles', 'old_capabilities',
            'old_role_providers', 'old_concurrency_ceiling', 'new_enabled',
            'new_roles', 'new_capabilities', 'new_role_providers',
            'new_concurrency_ceiling', 'updated_by_actor', 'occurred_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('machine_work_presence', [
            'spoke_id', 'team_id', 'roles', 'capabilities', 'role_providers',
            'current_claimed_item_count', 'concurrency_ceiling', 'observed_at',
        ]))->toBeTrue();
});

test('machine foundation models cast bounded records and expose their relations', function (): void {
    $owner = capstanUser();
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $owner->getKey(),
        'credential_id' => (string) Str::uuid7(),
        'inactive_at' => '2026-10-19 10:00:00',
        'orphaned_at' => null,
    ]);
    $team = Team::default();
    $report = MachineUsageReport::query()->create([
        'spoke_id' => $spoke->id,
        'source' => 'openusage-app',
        'source_version' => 'test-source',
        'availability' => 'available',
        'collected_at' => '2026-10-19 10:00:00',
        'last_attempt_at' => '2026-10-19 10:00:01',
        'last_success_received_at' => '2026-10-19 10:00:02',
    ]);
    $provider = $report->providers()->create([
        'provider' => 'codex',
        'plan' => 'team',
        'fetched_at' => '2026-10-19 09:59:00',
        'source_stale' => false,
        'expires_at' => '2026-10-19 10:15:00',
        'status' => 'ok',
    ]);
    $window = $provider->windows()->create([
        'slot' => 'primary',
        'kind' => 'consumption',
        'window_seconds' => 18000,
        'remaining_percent' => 72.5,
        'resets_at' => '2026-10-19 15:00:00',
    ]);
    $setting = MachineTeamWorkSetting::query()->create([
        'spoke_id' => $spoke->id,
        'team_id' => $team->id,
        'enabled' => true,
        'roles' => ['builder'],
        'capabilities' => ['php'],
        'role_providers' => ['builder' => 'codex'],
        'concurrency_ceiling' => 1,
        'updated_by_actor' => (string) $owner->getKey(),
    ]);
    $change = MachineWorkSettingChange::query()->create([
        'spoke_id' => $spoke->id,
        'team_id' => $team->id,
        'old_enabled' => null,
        'old_roles' => null,
        'old_capabilities' => null,
        'old_role_providers' => null,
        'old_concurrency_ceiling' => null,
        'new_enabled' => true,
        'new_roles' => ['builder'],
        'new_capabilities' => ['php'],
        'new_role_providers' => ['builder' => 'codex'],
        'new_concurrency_ceiling' => 1,
        'updated_by_actor' => (string) $owner->getKey(),
        'occurred_at' => '2026-10-19 10:00:00',
    ]);
    $presence = MachineWorkPresence::query()->create([
        'spoke_id' => $spoke->id,
        'team_id' => $team->id,
        'roles' => ['builder'],
        'capabilities' => ['php'],
        'role_providers' => ['builder' => 'codex'],
        'current_claimed_item_count' => 0,
        'concurrency_ceiling' => 1,
        'observed_at' => '2026-10-19 10:00:00',
    ]);

    expect($spoke->refresh()->inactive_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($spoke->usageReport->is($report))->toBeTrue()
        ->and($report->spoke->is($spoke))->toBeTrue()
        ->and($report->providers->sole()->is($provider))->toBeTrue()
        ->and($provider->windows->sole()->is($window))->toBeTrue()
        ->and($window->remaining_percent)->toBe('72.500')
        ->and($spoke->workSettings->sole()->is($setting))->toBeTrue()
        ->and($setting->roles)->toBe(['builder'])
        ->and($setting->role_providers)->toBe(['builder' => 'codex'])
        ->and($spoke->workSettingChanges->sole()->is($change))->toBeTrue()
        ->and($change->occurred_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($spoke->workPresence->sole()->is($presence))->toBeTrue()
        ->and($presence->current_claimed_item_count)->toBe(0);

    expect(fn () => $change->forceFill(['updated_by_actor' => 'changed'])->save())
        ->toThrow(LogicException::class, 'append-only');
});

test('machine foundation uniqueness and foreign key behavior are enforced', function (): void {
    $owner = capstanUser();
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $owner->getKey(),
        'credential_id' => (string) Str::uuid7(),
    ]);
    $team = Team::default();
    $setting = MachineTeamWorkSetting::query()->create([
        'spoke_id' => $spoke->id,
        'team_id' => $team->id,
        'enabled' => true,
        'roles' => [],
        'capabilities' => [],
        'role_providers' => [],
        'concurrency_ceiling' => 1,
        'updated_by_actor' => (string) $owner->getKey(),
    ]);
    MachineWorkSettingChange::query()->create([
        'spoke_id' => $spoke->id,
        'team_id' => $team->id,
        'new_enabled' => true,
        'new_roles' => [],
        'new_capabilities' => [],
        'new_role_providers' => [],
        'new_concurrency_ceiling' => 1,
        'updated_by_actor' => (string) $owner->getKey(),
        'occurred_at' => now(),
    ]);

    expect(fn () => MachineTeamWorkSetting::query()->create($setting->only([
        'spoke_id', 'team_id', 'enabled', 'roles', 'capabilities', 'role_providers',
        'concurrency_ceiling', 'updated_by_actor',
    ])))->toThrow(QueryException::class);

    expect(fn () => $spoke->delete())->toThrow(QueryException::class)
        ->and(fn () => $team->delete())->toThrow(QueryException::class);
});

/** @return array{spoke: Spoke, team: Team, actor: string} */
function boundedMachineFoundation(): array
{
    $owner = capstanUser();
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $owner->getKey(),
        'credential_id' => (string) Str::uuid7(),
    ]);

    return ['spoke' => $spoke, 'team' => Team::default(), 'actor' => (string) $owner->getKey()];
}

/** @return array<string, mixed> */
function boundedWorkProfile(array $overrides = []): array
{
    $roles = array_map(fn (int $index): string => 'r'.$index, range(1, 25));
    $roles[24] = str_repeat('r', 64);
    $capabilities = array_map(fn (int $index): string => 'c'.$index, range(1, 25));
    $capabilities[24] = str_repeat('c', 64);
    $providers = array_map(fn (int $index): string => 'p'.$index, range(1, 25));
    $providers[24] = str_repeat('p', 64);

    return array_replace([
        'enabled' => true,
        'roles' => $roles,
        'capabilities' => $capabilities,
        'role_providers' => array_combine($roles, $providers),
        'concurrency_ceiling' => 25,
    ], $overrides);
}

test('Work profile bounds accept limits and reject every limit plus one class', function (): void {
    $machine = boundedMachineFoundation();
    $valid = boundedWorkProfile();
    MachineTeamWorkSetting::query()->create([
        ...$valid,
        'spoke_id' => $machine['spoke']->id,
        'team_id' => $machine['team']->id,
        'updated_by_actor' => $machine['actor'],
    ]);

    $invalidProfiles = [
        [...$valid, 'roles' => [...$valid['roles'], 'overflow']],
        [...$valid, 'capabilities' => [...$valid['capabilities'], 'overflow']],
        [...$valid, 'role_providers' => [...$valid['role_providers'], 'undeclared' => 'codex']],
        [...$valid, 'roles' => [str_repeat('x', 65)], 'role_providers' => []],
        [...$valid, 'roles' => ['builder'], 'role_providers' => ['builder' => str_repeat('x', 65)]],
        [...$valid, 'roles' => ['builder'], 'role_providers' => ['builder' => 'Invalid Provider']],
        [...$valid, 'concurrency_ceiling' => 0],
        [...$valid, 'concurrency_ceiling' => 26],
    ];

    foreach ($invalidProfiles as $index => $profile) {
        expect(fn () => MachineTeamWorkSetting::query()->create([
            ...$profile,
            'spoke_id' => $machine['spoke']->id,
            'team_id' => Team::query()->create(['name' => 'Bounds '.$index, 'slug' => 'bounds-'.$index])->id,
            'updated_by_actor' => $machine['actor'],
        ]))->toThrow(InvalidArgumentException::class);
    }
});

test('Work presence and append-only audit validate complete old and new bounded profiles', function (): void {
    $machine = boundedMachineFoundation();
    $valid = boundedWorkProfile();
    MachineWorkPresence::query()->create([
        ...$valid,
        'spoke_id' => $machine['spoke']->id,
        'team_id' => $machine['team']->id,
        'current_claimed_item_count' => 25,
        'observed_at' => now(),
    ]);
    MachineWorkSettingChange::query()->create([
        'spoke_id' => $machine['spoke']->id,
        'team_id' => $machine['team']->id,
        'old_enabled' => false,
        'old_roles' => $valid['roles'],
        'old_capabilities' => $valid['capabilities'],
        'old_role_providers' => $valid['role_providers'],
        'old_concurrency_ceiling' => 25,
        'new_enabled' => true,
        'new_roles' => $valid['roles'],
        'new_capabilities' => $valid['capabilities'],
        'new_role_providers' => $valid['role_providers'],
        'new_concurrency_ceiling' => 25,
        'updated_by_actor' => $machine['actor'],
        'occurred_at' => now(),
    ]);

    expect(fn () => MachineWorkPresence::query()->create([
        ...$valid,
        'spoke_id' => $machine['spoke']->id,
        'team_id' => Team::query()->create(['name' => 'Count overflow', 'slug' => 'count-overflow'])->id,
        'current_claimed_item_count' => 26,
        'observed_at' => now(),
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => MachineWorkSettingChange::query()->create([
        'spoke_id' => $machine['spoke']->id,
        'team_id' => $machine['team']->id,
        'old_enabled' => false,
        'old_roles' => null,
        'old_capabilities' => null,
        'old_role_providers' => null,
        'old_concurrency_ceiling' => null,
        'new_enabled' => true,
        'new_roles' => [...$valid['roles'], 'overflow'],
        'new_capabilities' => [],
        'new_role_providers' => [],
        'new_concurrency_ceiling' => 25,
        'updated_by_actor' => $machine['actor'],
        'occurred_at' => now(),
    ]))->toThrow(InvalidArgumentException::class);
});

test('usage bounds accept exact maxima and reject domains cardinality grammar and precision overflow', function (): void {
    $machine = boundedMachineFoundation();
    $report = MachineUsageReport::query()->create([
        'spoke_id' => $machine['spoke']->id,
        'source' => 'openusage-sh',
        'source_version' => str_repeat('v', 32),
        'availability' => 'available',
        'collected_at' => now(),
        'last_attempt_at' => now(),
        'last_success_received_at' => now(),
    ]);
    foreach (range(1, 16) as $index) {
        MachineUsageProvider::query()->create([
            'machine_usage_report_id' => $report->id,
            'provider' => $index === 16 ? str_repeat('p', 64) : 'provider-'.$index,
            'plan' => str_repeat('p', 32),
            'fetched_at' => now(),
            'status' => 'ok',
        ]);
    }
    expect(fn () => MachineUsageProvider::query()->create([
        'machine_usage_report_id' => $report->id,
        'provider' => 'provider-overflow',
        'fetched_at' => now(),
        'status' => 'ok',
    ]))->toThrow(InvalidArgumentException::class);

    $provider = MachineUsageProvider::query()->where('machine_usage_report_id', $report->id)->firstOrFail();
    foreach (range(1, 8) as $index) {
        MachineUsageWindow::query()->create([
            'machine_usage_provider_id' => $provider->id,
            'slot' => $index === 8 ? str_repeat('s', 64) : 'slot-'.$index,
            'kind' => 'consumption',
            'window_seconds' => $index === 8 ? 2678400 : $index,
            'remaining_percent' => $index === 8 ? '100.000' : '0.000',
        ]);
    }
    expect(fn () => MachineUsageWindow::query()->create([
        'machine_usage_provider_id' => $provider->id,
        'slot' => 'slot-overflow',
        'kind' => 'consumption',
        'window_seconds' => 1,
        'remaining_percent' => 1,
    ]))->toThrow(InvalidArgumentException::class);

    $invalidReports = [
        ['source' => 'raw', 'source_version' => null, 'availability' => 'available'],
        ['source' => 'none', 'source_version' => str_repeat('x', 33), 'availability' => 'unavailable'],
        ['source' => 'none', 'source_version' => null, 'availability' => 'unknown'],
    ];
    foreach ($invalidReports as $index => $attributes) {
        $other = Spoke::query()->create(['actor_id' => $machine['actor'], 'credential_id' => (string) Str::uuid7()]);
        expect(fn () => MachineUsageReport::query()->create([
            ...$attributes,
            'spoke_id' => $other->id,
            'last_attempt_at' => now(),
        ]))->toThrow(InvalidArgumentException::class);
    }

    $otherReport = MachineUsageReport::query()->create([
        'spoke_id' => Spoke::query()->create(['actor_id' => $machine['actor'], 'credential_id' => (string) Str::uuid7()])->id,
        'source' => 'none', 'availability' => 'unavailable', 'failure_code' => 'not_found', 'last_attempt_at' => now(),
    ]);
    foreach ([
        ['provider' => 'Invalid Provider', 'plan' => null, 'status' => 'ok'],
        ['provider' => str_repeat('x', 65), 'plan' => null, 'status' => 'ok'],
        ['provider' => 'codex', 'plan' => str_repeat('x', 33), 'status' => 'ok'],
        ['provider' => 'codex', 'plan' => null, 'status' => 'bogus'],
    ] as $attributes) {
        expect(fn () => MachineUsageProvider::query()->create([
            ...$attributes,
            'machine_usage_report_id' => $otherReport->id,
            'fetched_at' => now(),
        ]))->toThrow(InvalidArgumentException::class);
    }
});

test('window values and nullable duration identity are enforced at the model and database boundaries', function (): void {
    $machine = boundedMachineFoundation();
    $report = MachineUsageReport::query()->create([
        'spoke_id' => $machine['spoke']->id,
        'source' => 'none', 'availability' => 'unavailable', 'failure_code' => 'not_found', 'last_attempt_at' => now(),
    ]);
    $provider = MachineUsageProvider::query()->create([
        'machine_usage_report_id' => $report->id,
        'provider' => 'codex', 'fetched_at' => now(), 'status' => 'unknown',
    ]);
    MachineUsageWindow::query()->create([
        'machine_usage_provider_id' => $provider->id,
        'slot' => 'balance', 'kind' => 'balance', 'window_seconds' => null, 'remaining_percent' => '100.000',
    ]);
    expect(fn () => MachineUsageWindow::query()->create([
        'machine_usage_provider_id' => $provider->id,
        'slot' => 'balance', 'kind' => 'balance', 'window_seconds' => null, 'remaining_percent' => '50.000',
    ]))->toThrow(QueryException::class);

    foreach ([
        ['slot' => 'bad slot', 'kind' => 'balance', 'window_seconds' => null, 'remaining_percent' => 1],
        ['slot' => 'negative', 'kind' => 'consumption', 'window_seconds' => 1, 'remaining_percent' => -1],
        ['slot' => 'over', 'kind' => 'consumption', 'window_seconds' => 1, 'remaining_percent' => '100.001'],
        ['slot' => 'precision', 'kind' => 'consumption', 'window_seconds' => 1, 'remaining_percent' => '1.0000'],
        ['slot' => 'zero', 'kind' => 'consumption', 'window_seconds' => 0, 'remaining_percent' => 1],
        ['slot' => 'long', 'kind' => 'consumption', 'window_seconds' => 2678401, 'remaining_percent' => 1],
        ['slot' => 'balance-duration', 'kind' => 'balance', 'window_seconds' => 1, 'remaining_percent' => 1],
        ['slot' => 'consumption-null', 'kind' => 'consumption', 'window_seconds' => null, 'remaining_percent' => 1],
        ['slot' => 'kind', 'kind' => 'unknown', 'window_seconds' => null, 'remaining_percent' => 1],
    ] as $attributes) {
        expect(fn () => MachineUsageWindow::query()->create([
            ...$attributes,
            'machine_usage_provider_id' => $provider->id,
        ]))->toThrow(InvalidArgumentException::class);
    }

    $databaseViolations = [
        fn () => DB::table('machine_usage_windows')->insert([
            'machine_usage_provider_id' => $provider->id,
            'slot' => 'raw-over', 'kind' => 'consumption', 'window_seconds' => 1,
            'remaining_percent' => 101, 'window_identity' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]),
        fn () => DB::table('machine_team_work_settings')->insert([
            'spoke_id' => $machine['spoke']->id, 'team_id' => $machine['team']->id,
            'enabled' => true, 'roles' => '[]', 'capabilities' => '[]', 'role_providers' => '[]',
            'concurrency_ceiling' => 0, 'updated_by_actor' => $machine['actor'],
            'created_at' => now(), 'updated_at' => now(),
        ]),
        fn () => DB::table('machine_work_setting_changes')->insert([
            'id' => (string) Str::uuid7(), 'spoke_id' => $machine['spoke']->id, 'team_id' => $machine['team']->id,
            'new_enabled' => true, 'new_roles' => '[]', 'new_capabilities' => '[]', 'new_role_providers' => '[]',
            'new_concurrency_ceiling' => 26, 'updated_by_actor' => $machine['actor'], 'occurred_at' => now(),
        ]),
        fn () => DB::table('machine_work_presence')->insert([
            'spoke_id' => $machine['spoke']->id,
            'team_id' => $machine['team']->id,
            'roles' => '[]', 'capabilities' => '[]', 'role_providers' => '[]',
            'current_claimed_item_count' => 2, 'concurrency_ceiling' => 1,
            'observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]),
    ];
    foreach ($databaseViolations as $violation) {
        expect(fn () => DB::transaction($violation))->toThrow(QueryException::class);
    }
});
