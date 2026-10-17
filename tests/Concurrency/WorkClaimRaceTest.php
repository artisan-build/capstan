<?php

use App\Enums\WorkItemState;
use App\Models\WorkItem;
use App\Work\WorkClaimService;
use App\Work\WorkDrainService;
use App\Work\WorkItemStateService;
use Carbon\CarbonImmutable;
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

/** @param array<string, mixed> $connection */
function workRaceProcessEnvironment(array $connection): array
{
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

    return $env;
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
    $env = workRaceProcessEnvironment($connection);

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

/**
 * @return array{team_id: int, item_id: string, first: array{actor: string, spoke: int, credential: string}, second: array{actor: string, spoke: int, credential: string}}
 */
function seedWorkFenceScenario(): array
{
    $now = CarbonImmutable::parse('2026-10-17 16:00:00 UTC');
    $teamId = DB::table('teams')->insertGetId([
        'name' => 'Work fence race team',
        'slug' => 'work-fence-race-'.Str::lower(Str::random(8)),
        'is_default' => false,
        'pool_work' => false,
        'default_failover' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $taskId = (string) Str::uuid7();
    $itemId = (string) Str::uuid7();
    DB::table('work_tasks')->insert([
        'id' => $taskId,
        'team_id' => $teamId,
        'title' => 'Test-created fence race task',
        'failover' => true,
        'required_capabilities' => '[]',
        'timebox_minutes' => 30,
        'creator_actor' => 'race-test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('work_items')->insert([
        'id' => $itemId,
        'work_task_id' => $taskId,
        'role' => 'builder',
        'state' => WorkItemState::Queued->value,
        'fence' => 0,
        'attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $claimants = [];

    foreach (['first', 'second'] as $name) {
        $actor = "fence-$name-actor";
        $credential = (string) Str::uuid7();
        $spoke = DB::table('spokes')->insertGetId([
            'actor_id' => $actor,
            'credential_id' => $credential,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $claimants[$name] = compact('actor', 'spoke', 'credential');
    }

    $claimed = resolve(WorkClaimService::class)->claim(
        $teamId,
        $claimants['first']['actor'],
        $claimants['first']['spoke'],
        ['builder'],
        [],
        1,
        $now,
        60,
    );
    expect($claimed)->toHaveCount(1)
        ->and($claimed[0]->id)->toBe($itemId)
        ->and($claimed[0]->fence)->toBe(1);

    return [
        'team_id' => $teamId,
        'item_id' => $itemId,
        'first' => $claimants['first'],
        'second' => $claimants['second'],
    ];
}

/**
 * @param  array<string, mixed>  $connection
 * @param  array{team_id: int, item_id: string, first: array{actor: string, spoke: int, credential: string}, second: array{actor: string, spoke: int, credential: string}}  $scenario
 * @param  Closure(): void  $whilePaused
 * @return array<string, mixed>
 */
function runWorkFenceProcess(string $mode, array $connection, array $scenario, Closure $whilePaused): array
{
    $barrier = storage_path('framework/testing/work-fence-barrier-'.Str::lower(Str::random(16)));
    mkdir($barrier, 0777, true);
    $process = new Process([
        PHP_BINARY,
        base_path('tests/Support/work-fence-worker.php'),
        $mode,
        $barrier,
        (string) $scenario['team_id'],
        $scenario['item_id'],
        $scenario['first']['actor'],
        $scenario['first']['credential'],
        '1',
    ], base_path(), workRaceProcessEnvironment($connection), null, 90);
    $process->start();

    try {
        $deadline = microtime(true) + 30;

        while (! file_exists($barrier.'/ready')) {
            if ($process->isTerminated()) {
                throw new RuntimeException('Fence worker failed before the barrier: '.substr($process->getErrorOutput(), 0, 2000));
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Fence worker did not reach the barrier.');
            }

            usleep(10_000);
        }

        $whilePaused();
        touch($barrier.'/release');
        $exit = $process->wait();

        if ($exit !== 0) {
            throw new RuntimeException('Fence worker failed: '.substr($process->getErrorOutput(), 0, 2000));
        }

        $output = $process->getOutput();
        $marker = 'WORK_FENCE_RESULT=';
        $position = strrpos($output, $marker);

        if ($position === false) {
            throw new RuntimeException('Fence worker returned no result marker: '.substr($output, 0, 2000));
        }

        /** @var array<string, mixed> $result */
        $result = json_decode(trim(substr($output, $position + strlen($marker))), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    } finally {
        if ($process->isRunning()) {
            $process->stop(1);
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

test('transition and renew lose atomically when another process reclaims the fence', function (): void {
    $database = provisionWorkRaceDatabase();

    try {
        foreach (['transition', 'renew'] as $mode) {
            $scenario = seedWorkFenceScenario();
            $newClaim = null;
            $result = runWorkFenceProcess(
                $mode,
                $database['connection'],
                $scenario,
                function () use ($scenario, &$newClaim): void {
                    $expiredAt = CarbonImmutable::parse('2026-10-17 16:01:01 UTC');
                    resolve(WorkItemStateService::class)->expireLease(
                        WorkItem::query()->findOrFail($scenario['item_id']),
                        'system',
                        'fence-race-expiry',
                        $expiredAt,
                    );
                    $claimed = resolve(WorkClaimService::class)->claim(
                        $scenario['team_id'],
                        $scenario['second']['actor'],
                        $scenario['second']['spoke'],
                        ['builder'],
                        [],
                        1,
                        $expiredAt,
                        300,
                    );
                    expect($claimed)->toHaveCount(1);
                    $newClaim = $claimed[0];
                },
            );
            $reclaimed = WorkItem::query()->findOrFail($scenario['item_id']);
            $newLease = $reclaimed->lease_until;

            expect($newClaim)->not->toBeNull()
                ->and($reclaimed->fence)->toBe(2)
                ->and($reclaimed->claimant_actor)->toBe($scenario['second']['actor'])
                ->and($reclaimed->transitions()->count())->toBe(3);

            if ($mode === 'transition') {
                expect($result)->toBe([
                    'status' => 'rejected',
                    'http_status' => 409,
                    'code' => 'claim_lost',
                ]);
                $succeeded = resolve(WorkDrainService::class)->transition(
                    $scenario['team_id'],
                    $scenario['second']['actor'],
                    $scenario['second']['credential'],
                    $scenario['item_id'],
                    2,
                    'delivered',
                    CarbonImmutable::parse('2026-10-17 16:01:02 UTC'),
                );
                expect($succeeded->state)->toBe(WorkItemState::Delivered)
                    ->and($succeeded->transitions()->count())->toBe(4);
            } else {
                expect($result)->toBe(['status' => 'lost'])
                    ->and($reclaimed->lease_until?->equalTo($newLease))->toBeTrue();
                $succeeded = resolve(WorkDrainService::class)->renew(
                    $scenario['team_id'],
                    $scenario['second']['actor'],
                    $scenario['second']['credential'],
                    [['item_id' => $scenario['item_id'], 'fence' => 2]],
                    CarbonImmutable::parse('2026-10-17 16:01:31 UTC'),
                );
                expect($succeeded[0]['status'])->toBe('renewed')
                    ->and($succeeded[0]['item']->lease_until?->isAfter($newLease))->toBeTrue();
            }
        }
    } finally {
        cleanupWorkRaceDatabase($database);
    }
});
