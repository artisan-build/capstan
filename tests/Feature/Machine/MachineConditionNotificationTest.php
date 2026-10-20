<?php

use App\Machine\IngestMachineUsage;
use App\Machine\MachineConditionNotifier;
use App\Machine\MachineFindingPayload;
use App\Models\MachineConditionState;
use App\Models\Spoke;
use App\Notifications\MachineConditionNotification;
use App\Postmaster\MarkInactiveMachines;
use App\Support\BoundCredentialSpokeResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(fn () => Date::setTestNow('2026-10-20 12:00:00'));
afterEach(fn () => Date::setTestNow());

function conditionMachine(): array
{
    $owner = capstanUser(['name' => 'Condition owner']);
    $machine = Spoke::query()->create([
        'actor_id' => (string) $owner->id,
        'credential_id' => (string) Str::uuid(),
        'name' => 'Condition machine',
        'last_polled_at' => now(),
        'probe_status' => 'green',
    ]);

    return [$owner, $machine];
}

test('every registered machine condition deduplicates uninterrupted state and notifies only its owner on recovery and recurrence', function (): void {
    [$owner, $machine] = conditionMachine();
    $other = capstanUser();
    $notifier = resolve(MachineConditionNotifier::class);

    foreach ([
        MachineConditionNotifier::PROBE_FAILURE,
        MachineConditionNotifier::INACTIVE,
        MachineConditionNotifier::USAGE_UNAVAILABLE,
        MachineConditionNotifier::RUNNER_CHECK,
    ] as $code) {
        expect($notifier->open($machine, $code, now()))->toBeTrue()
            ->and($notifier->open($machine, $code, now()->addSecond()))->toBeFalse()
            ->and($notifier->recover($machine, $code, now()->addMinutes(1)))->toBeTrue()
            ->and($notifier->recover($machine, $code, now()->addMinutes(2)))->toBeFalse()
            ->and($notifier->open($machine, $code, now()->addMinutes(3)))->toBeTrue();
    }

    expect($owner->notifications)->toHaveCount(12)
        ->and($other->notifications)->toBeEmpty()
        ->and(MachineConditionState::query()->where('active', true)->count())->toBe(4);
    foreach ($owner->notifications as $notification) {
        expect($notification->type)->toBe(MachineConditionNotification::class)
            ->and(array_keys($notification->data))->toBe([
                'machine_id', 'display_name', 'finding_code', 'state_transition', 'occurred_at',
            ])
            ->and(array_keys($notification->data))->toHaveCount(5);
        $encoded = json_encode($notification->data, JSON_THROW_ON_ERROR);
        foreach (['credential', 'private@example.test', '/secret/path', 'diagnostic'] as $forbidden) {
            expect($encoded)->not->toContain($forbidden);
        }
    }
});

test('inactivity sweep and a later authenticated poll emit one transition and recovery', function (): void {
    [$owner, $machine] = conditionMachine();
    $machine->forceFill(['last_polled_at' => now()->subMinutes(6)])->save();

    expect((resolve(MarkInactiveMachines::class))(now()))->toBe(1)
        ->and((resolve(MarkInactiveMachines::class))(now()->addSecond()))->toBe(0)
        ->and($owner->fresh()->notifications)->toHaveCount(1);

    resolve(BoundCredentialSpokeResolver::class)->resolve(
        (string) $owner->id,
        $machine->credential_id,
        now()->addMinutes(1),
    );
    expect($machine->refresh()->inactive_at)->toBeNull()
        ->and($owner->fresh()->notifications)->toHaveCount(2)
        ->and($owner->fresh()->notifications->pluck('data.state_transition')->sort()->values()->all())->toBe(['recovered', 'started']);
});

test('usage unavailability notifies only after fifteen continuous minutes and recovery closes it', function (): void {
    [$owner, $machine] = conditionMachine();
    $ingest = resolve(IngestMachineUsage::class);
    $unavailable = [
        'source' => 'none', 'source_version' => null, 'availability' => 'unavailable',
        'collected_at' => now(), 'failure_code' => 'not_found', 'providers' => [],
    ];
    expect($ingest->ingest($machine, $unavailable, now()))->toBe('accepted')
        ->and($owner->fresh()->notifications)->toBeEmpty();

    Date::setTestNow(now()->addMinutes(16));
    $unavailable['collected_at'] = now();
    expect($ingest->ingest($machine, $unavailable, now()))->toBe('accepted')
        ->and($owner->fresh()->notifications)->toHaveCount(1);

    Date::setTestNow(now()->addMinutes(6));
    $available = [
        'source' => 'openusage-sh', 'source_version' => '0.26.1', 'availability' => 'available',
        'collected_at' => now(), 'failure_code' => null,
        'providers' => [[
            'provider' => 'codex', 'plan' => null, 'fetched_at' => now(), 'source_stale' => null,
            'expires_at' => null, 'status' => 'ok', 'windows' => [[
                'slot' => 'primary', 'kind' => 'consumption', 'window_seconds' => 604800,
                'remaining_percent' => 75, 'resets_at' => now()->addWeek(),
            ]],
        ]],
    ];
    expect($ingest->ingest($machine, $available, now()))->toBe('accepted')
        ->and($owner->fresh()->notifications)->toHaveCount(2)
        ->and(MachineConditionState::query()->where('finding_code', MachineConditionNotifier::USAGE_UNAVAILABLE)->sole()->active)->toBeFalse();
});

test('finding parsing is bounded ignores unknown and malformed members and stably orders valid observations', function (): void {
    $payload = json_decode(json_encode([
        ['code' => 'runner_check', 'severity' => 'recovered', 'observed_at' => '2026-10-20T12:02:00+00:00'],
        ['code' => 'unknown_runner_text', 'severity' => 'critical', 'observed_at' => '2026-10-20T12:00:00+00:00'],
        ['code' => 'runner_check', 'severity' => 'critical', 'observed_at' => 'not-a-time'],
        ['code' => 'runner_check', 'severity' => 'warning', 'observed_at' => '2026-10-20T12:01:00+00:00'],
        ['code' => 'runner_check', 'severity' => 'critical', 'observed_at' => '2026-10-20T12:01:00+00:00'],
    ], JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
    $result = resolve(MachineFindingPayload::class)->parse($payload);

    expect($result['ignored_unknown'])->toBe(1)
        ->and($result['ignored_invalid'])->toBe(1)
        ->and(array_column($result['accepted'], 'recovered'))->toBe([false, false, true])
        ->and($result['accepted'][0]['observed_at']->toJSON())->toBe('2026-10-20T12:01:00.000000Z')
        ->and($result['accepted'][1]['observed_at']->toJSON())->toBe('2026-10-20T12:01:00.000000Z');

    $over = resolve(MachineFindingPayload::class)->parse(array_fill(0, 26, new stdClass));
    expect($over)->toBe(['accepted' => [], 'ignored_unknown' => 0, 'ignored_invalid' => 26]);
});

test('rolling back preserves a notifications table that existed before this migration', function (): void {
    $default = DB::getDefaultConnection();
    config(['database.connections.machine_condition_migration' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('machine_condition_migration');

    try {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('spokes', fn (Blueprint $table) => $table->id());
        Schema::create('machine_usage_reports', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('last_success_received_at')->nullable();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('preexisting_marker');
        });
        $migration = require database_path('migrations/2026_10_20_020000_create_machine_condition_notifications.php');
        $migration->up();
        expect(Schema::hasTable('machine_condition_states'))->toBeTrue();

        $migration->down();
        expect(Schema::hasTable('notifications'))->toBeTrue()
            ->and(Schema::hasColumn('notifications', 'preexisting_marker'))->toBeTrue()
            ->and(Schema::hasTable('machine_condition_states'))->toBeFalse();
    } finally {
        DB::disconnect('machine_condition_migration');
        DB::setDefaultConnection($default);
    }
});
