<?php

use App\Enums\WorkItemState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/** @return array{connection: array<string, mixed>, database: string, sqlite_path: string|null, original: string} */
function provisionWorkRaceDatabase(): array
{
    expect(app()->environment('testing'))->toBeTrue();
    $original = DB::getDefaultConnection();
    /** @var array<string, mixed> $base */
    $base = config("database.connections.$original");
    $driver = $base['driver'] ?? null;
    expect($driver)->toBeIn(['sqlite', 'pgsql']);
    $suffix = 'capstan_work_race_'.strtolower(Str::random(16));
    $sqlitePath = null;

    if ($driver === 'sqlite') {
        $sqlitePath = storage_path("framework/testing/$suffix.sqlite");
        $directory = dirname($sqlitePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        touch($sqlitePath);
        $database = $sqlitePath;
        $connection = array_replace($base, [
            'database' => $database,
            'busy_timeout' => 15_000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ]);
    } else {
        expect($base['host'] ?? null)->toBeIn(['127.0.0.1', 'localhost']);
        $database = $suffix;
        DB::connection($original)->statement('CREATE DATABASE "'.$database.'"');
        $connection = array_replace($base, ['database' => $database]);
    }

    try {
        config(['database.connections.work_race' => $connection]);
        DB::setDefaultConnection('work_race');
        DB::purge('work_race');
        Artisan::call('migrate:fresh', ['--database' => 'work_race', '--force' => true]);
    } catch (Throwable $exception) {
        DB::purge('work_race');
        DB::setDefaultConnection($original);

        if ($sqlitePath !== null) {
            foreach ([$sqlitePath, $sqlitePath.'-shm', $sqlitePath.'-wal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        } else {
            $admin = DB::connection($original);
            $admin->statement('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()', [$database]);
            $admin->statement('DROP DATABASE "'.$database.'"');
        }

        throw $exception;
    }

    return [
        'connection' => $connection,
        'database' => $database,
        'sqlite_path' => $sqlitePath,
        'original' => $original,
    ];
}

/** @param array{connection: array<string, mixed>, database: string, sqlite_path: string|null, original: string} $database */
function cleanupWorkRaceDatabase(array $database): void
{
    DB::purge('work_race');
    DB::setDefaultConnection($database['original']);

    if ($database['sqlite_path'] !== null) {
        foreach ([$database['sqlite_path'], $database['sqlite_path'].'-shm', $database['sqlite_path'].'-wal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return;
    }

    expect($database['database'])->toStartWith('capstan_work_race_');
    $admin = DB::connection($database['original']);
    $admin->statement('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()', [$database['database']]);
    $admin->statement('DROP DATABASE "'.$database['database'].'"');
}

/** @return array{team_id: int, workers: list<array{actor: string, spoke: int}>} */
function seedWorkRaceQueue(int $count, ?int $teamId = null): array
{
    $now = '2026-10-17 15:00:00';

    if ($teamId === null) {
        $teamId = DB::table('teams')->insertGetId([
            'name' => 'Work claim race team',
            'slug' => 'work-claim-race-'.Str::lower(Str::random(8)),
            'is_default' => false,
            'pool_work' => false,
            'default_failover' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $workers = [];

    foreach (range(1, 10) as $index) {
        $actor = "race-actor-$index";
        $spoke = DB::table('spokes')->insertGetId([
            'actor_id' => $actor,
            'credential_id' => (string) Str::uuid7(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $workers[] = compact('actor', 'spoke');
    }

    $taskId = (string) Str::uuid7();
    DB::table('work_tasks')->insert([
        'id' => $taskId,
        'team_id' => $teamId,
        'title' => 'Test-created concurrent claim task',
        'failover' => true,
        'required_capabilities' => json_encode(['php'], JSON_THROW_ON_ERROR),
        'timebox_minutes' => 30,
        'creator_actor' => 'race-test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $rows = [];

    foreach (range(1, $count) as $index) {
        $rows[] = [
            'id' => (string) Str::uuid7(),
            'work_task_id' => $taskId,
            'role' => 'builder',
            'state' => WorkItemState::Queued->value,
            'fence' => 0,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('work_items')->insert($chunk);
    }

    return ['team_id' => $teamId, 'workers' => $workers];
}

/**
 * @param  list<array{actor: string, spoke: int}>  $workers
 * @param  list<int>  $capacities
 * @param  array<string, mixed>  $connection
 * @return list<list<string>>
 */
function runWorkClaimProcesses(string $mode, int $teamId, array $workers, array $capacities, array $connection): array
{
    $barrier = storage_path('framework/testing/work-race-barrier-'.Str::lower(Str::random(16)));
    mkdir($barrier, 0777, true);
    $processes = [];
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => (string) $connection['driver'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_URL' => '',
    ];

    foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $name) {
        if (isset($connection[$key])) {
            $env[$name] = (string) $connection[$key];
        }
    }

    try {
        foreach ($workers as $index => $worker) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/work-claim-worker.php'),
                $mode,
                (string) $index,
                $barrier,
                (string) $teamId,
                $worker['actor'],
                (string) $worker['spoke'],
                (string) $capacities[$index],
            ], base_path(), $env, null, 90);
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 30;

        while (count(glob($barrier.'/ready-*') ?: []) !== count($processes)) {
            foreach ($processes as $process) {
                if ($process->isTerminated() && ! $process->isSuccessful()) {
                    throw new RuntimeException('Claim worker failed before release: '.substr($process->getErrorOutput(), 0, 2000));
                }
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Claim workers did not reach the barrier.');
            }

            usleep(10_000);
        }

        touch($barrier.'/release');
        $results = [];

        foreach ($processes as $process) {
            $exit = $process->wait();

            if ($exit !== 0) {
                throw new RuntimeException('Claim worker failed: '.substr($process->getErrorOutput(), 0, 2000));
            }

            /** @var array{worker: int, ids: list<string>} $result */
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $results[$result['worker']] = $result['ids'];
        }

        ksort($results);

        return array_values($results);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($barrier);
    }
}

test('conditional claim guard survives a real multiprocess race and the unsafe control duplicates', function (): void {
    $database = provisionWorkRaceDatabase();
    $capacities = [20, 21, 22, 23, 24, 25, 25, 25, 25, 25];

    try {
        $safe = seedWorkRaceQueue(200);
        $safeResults = runWorkClaimProcesses(
            'safe',
            $safe['team_id'],
            $safe['workers'],
            $capacities,
            $database['connection'],
        );
        $safeIds = array_merge(...$safeResults);

        expect($safeResults)->toHaveCount(10)
            ->and(count($safeIds))->toBe(200)
            ->and(count(array_unique($safeIds)))->toBe(200)
            ->and(DB::table('work_items')->where('state', WorkItemState::Claimed->value)->count())->toBe(200)
            ->and(DB::table('work_items')->where('state', WorkItemState::Queued->value)->count())->toBe(0)
            ->and(DB::table('work_item_transitions')->count())->toBe(200);

        foreach ($safeResults as $index => $ids) {
            expect(count($ids))->toBeLessThanOrEqual($capacities[$index]);
        }

        $unsafe = seedWorkRaceQueue(200, $safe['team_id']);
        $unsafeResults = runWorkClaimProcesses(
            'unsafe',
            $unsafe['team_id'],
            $unsafe['workers'],
            $capacities,
            $database['connection'],
        );
        $unsafeIds = array_merge(...$unsafeResults);

        expect(count($unsafeIds))->toBeGreaterThan(count(array_unique($unsafeIds)));
    } finally {
        cleanupWorkRaceDatabase($database);
    }
});
