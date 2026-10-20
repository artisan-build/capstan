<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Machine\MachineConditionNotifier;
use App\Models\MachineConditionState;
use App\Models\Spoke;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/** @param array<string, mixed> $connection @return array<string, string> */
function inactivePollRaceEnvironment(array $connection, string $appKey): array
{
    return [
        'APP_ENV' => 'testing',
        'APP_KEY' => $appKey,
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => (string) $connection['database'],
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'DB_URL' => '',
    ];
}

test('a fresh poll holding the machine lock cannot be overwritten by the inactivity sweep', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The inactivity/poll interleaving requires PostgreSQL row locks.');
    }

    $original = DB::getDefaultConnection();
    /** @var array<string, mixed> $base */
    $base = config("database.connections.$original");
    expect($base['host'] ?? null)->toBeIn(['127.0.0.1', 'localhost']);
    $database = 'capstan_inactive_poll_'.Str::lower(Str::random(12));
    DB::connection($original)->statement('CREATE DATABASE "'.$database.'"');
    $connection = array_replace($base, ['database' => $database]);
    config(['database.connections.inactive_poll_race' => $connection]);
    DB::setDefaultConnection('inactive_poll_race');
    DB::purge('inactive_poll_race');
    $barrier = storage_path('framework/testing/inactive-poll-race-'.Str::lower(Str::random(12)));
    mkdir($barrier, 0777, true);
    $processes = [];

    try {
        Artisan::call('migrate:fresh', ['--database' => 'inactive_poll_race', '--force' => true]);
        $appKey = 'base64:'.base64_encode(str_repeat('i', 32));
        config(['app.key' => $appKey]);
        $owner = capstanUser();
        $issued = capstanBoundBearer($owner, CapstanCredentialDeclaration::MACHINE);
        $now = CarbonImmutable::parse('2026-10-20T12:00:00Z');
        $machine = Spoke::query()->create([
            'actor_id' => (string) $owner->id,
            'credential_id' => $issued['credential']->id,
            'credential_backed' => true,
            'last_polled_at' => $now->subSeconds(301),
        ]);
        $environment = [
            ...inactivePollRaceEnvironment($connection, $appKey),
            'INACTIVE_RACE_NOW' => $now->toJSON(),
            'INACTIVE_RACE_ACTOR_ID' => (string) $owner->id,
            'INACTIVE_RACE_CREDENTIAL_ID' => $issued['credential']->id,
        ];

        $poll = new Process([PHP_BINARY, base_path('tests/Support/inactive-poll-race-worker.php'), 'poll', $barrier], base_path(), $environment, null, 60);
        $poll->start();
        $processes[] = $poll;
        $deadline = microtime(true) + 30;
        while (! file_exists($barrier.'/poll-locked')) {
            if ($poll->isTerminated()) {
                throw new RuntimeException('Poll worker failed before locking: '.$poll->getErrorOutput());
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Poll worker did not acquire the machine lock.');
            }
            Sleep::usleep(10_000);
        }

        $sweep = new Process([PHP_BINARY, base_path('tests/Support/inactive-poll-race-worker.php'), 'sweep', $barrier], base_path(), $environment, null, 60);
        $sweep->start();
        $processes[] = $sweep;
        $deadline = microtime(true) + 30;
        while (! file_exists($barrier.'/sweep-selected')) {
            if ($sweep->isTerminated()) {
                throw new RuntimeException('Sweep worker failed before selecting the stale candidate: '.$sweep->getErrorOutput());
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Sweep worker did not select the stale candidate.');
            }
            Sleep::usleep(10_000);
        }

        touch($barrier.'/release-poll');
        expect($poll->wait())->toBe(0, $poll->getErrorOutput())
            ->and($sweep->wait())->toBe(0, $sweep->getErrorOutput());
        $pollResult = json_decode($poll->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $sweepResult = json_decode($sweep->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        DB::purge('inactive_poll_race');

        expect($pollResult)->toBe(['mode' => 'poll', 'committed' => true])
            ->and($sweepResult)->toBe(['mode' => 'sweep', 'count' => 0])
            ->and($machine->fresh()?->last_polled_at?->toISOString())->toBe($now->toISOString())
            ->and($machine->fresh()?->inactive_at)->toBeNull()
            ->and(MachineConditionState::query()->where('spoke_id', $machine->id)->where('finding_code', MachineConditionNotifier::INACTIVE)->doesntExist())->toBeTrue()
            ->and(DB::table('notifications')->count())->toBe(0);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        DB::purge('inactive_poll_race');
        DB::setDefaultConnection($original);
        $admin = DB::connection($original);
        $admin->statement('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()', [$database]);
        $admin->statement('DROP DATABASE IF EXISTS "'.$database.'"');
        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
    }
});
