<?php

use App\Models\MachineTeamWorkSetting;
use App\Models\MachineUsageReport;
use App\Models\MachineWorkPresence;
use App\Models\MachineWorkSettingChange;
use App\Models\Spoke;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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
            'remaining_percent', 'resets_at',
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
