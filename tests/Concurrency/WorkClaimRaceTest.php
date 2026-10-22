<?php

use App\Enums\WorkItemState;
use App\Models\MachineTeamWorkSetting;
use App\Models\MachineWorkPresence;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use App\Work\WorkClaimService;
use App\Work\WorkDrainService;
use App\Work\WorkItemStateService;
use App\Work\WorkOrchestrationService;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
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

    $teamId ??= DB::table('teams')->insertGetId([
        'name' => 'Work claim race team',
        'slug' => 'work-claim-race-'.Str::lower(Str::random(8)),
        'is_default' => false,
        'pool_work' => true,
        'default_failover' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

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
        'owner_actor' => 'race-test',
        'priority' => 'P2',
        'routing_pool_work' => true,
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

            Sleep::usleep(10_000);
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
 * @param  list<array{actor: string, spoke: int, credential: string}>  $workers
 * @param  array<string, mixed>  $connection
 * @return list<list<string>>
 */
function runMachineDrainProcesses(int $teamId, array $workers, array $connection): array
{
    $barrier = storage_path('framework/testing/machine-drain-barrier-'.Str::lower(Str::random(16)));
    mkdir($barrier, 0777, true);
    $processes = [];

    try {
        foreach ($workers as $index => $worker) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/work-machine-claim-worker.php'),
                (string) $index,
                $barrier,
                (string) $teamId,
                $worker['actor'],
                $worker['credential'],
                '1',
            ], base_path(), workRaceProcessEnvironment($connection), null, 90);
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 30;
        while (count(glob($barrier.'/ready-*') ?: []) !== count($processes)) {
            foreach ($processes as $process) {
                if ($process->isTerminated() && ! $process->isSuccessful()) {
                    throw new RuntimeException('Machine drain worker failed before release: '.substr($process->getErrorOutput(), 0, 2000));
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Machine drain workers did not reach the barrier.');
            }
            Sleep::usleep(10_000);
        }

        touch($barrier.'/release');
        $results = [];
        foreach ($processes as $process) {
            if ($process->wait() !== 0) {
                throw new RuntimeException('Machine drain worker failed: '.substr($process->getErrorOutput(), 0, 2000));
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

/** @param array<string, mixed> $connection
 * @return list<string>
 */
function runP0CreationProcesses(int $teamId, string $owner, array $connection): array
{
    $barrier = storage_path('framework/testing/work-p0-barrier-'.Str::lower(Str::random(16)));
    mkdir($barrier, 0777, true);
    $processes = [];

    try {
        foreach (range(0, 1) as $worker) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/work-p0-worker.php'),
                (string) $worker,
                $barrier,
                (string) $teamId,
                $owner,
                'Concurrent P0 '.$worker,
            ], base_path(), workRaceProcessEnvironment($connection), null, 90);
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 30;
        while (count(glob($barrier.'/ready-*') ?: []) !== 2) {
            foreach ($processes as $process) {
                if ($process->isTerminated()) {
                    throw new RuntimeException('P0 worker failed before release: '.$process->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('P0 workers did not reach the barrier.');
            }
            Sleep::usleep(10_000);
        }

        touch($barrier.'/release');
        $results = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $results[$result['worker']] = $result['status'];
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
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
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
        'pool_work' => true,
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
        'owner_actor' => 'fence-first-actor',
        'priority' => 'P2',
        'routing_pool_work' => true,
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

            Sleep::usleep(10_000);
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

test('simultaneous pooled contenders cannot bypass the deterministic machine winner on SQLite', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Dedicated SQLite deterministic machine routing race proof.');
    }

    $database = provisionWorkRaceDatabase();

    try {
        $team = Team::query()->create([
            'name' => 'Deterministic routing race',
            'slug' => 'deterministic-routing-race',
            'pool_work' => true,
        ]);
        $workers = [];
        foreach (range(1, 2) as $index) {
            $user = new User;
            $user->forceFill([
                'name' => "Routing worker {$index}",
                'email' => "routing-worker-{$index}@example.test",
                'password' => bcrypt('test-created-password'),
                'role' => UserRole::Member->value,
                'status' => 'active',
            ])->save();
            $machine = Spoke::query()->create([
                'actor_id' => (string) $user->id,
                'credential_id' => (string) Str::uuid7(),
                'last_polled_at' => '2026-10-17 16:00:00',
                'probe_status' => 'green',
            ]);
            MachineTeamWorkSetting::query()->create([
                'spoke_id' => $machine->id,
                'team_id' => $team->id,
                'enabled' => true,
                'roles' => ['builder'],
                'capabilities' => ['php'],
                'role_providers' => ['builder' => 'codex'],
                'concurrency_ceiling' => 1,
                'updated_by_actor' => (string) $user->id,
            ]);
            MachineWorkPresence::query()->create([
                'spoke_id' => $machine->id,
                'team_id' => $team->id,
                'roles' => ['builder'],
                'capabilities' => ['php'],
                'role_providers' => ['builder' => 'codex'],
                'current_claimed_item_count' => 0,
                'available_capacity' => 1,
                'concurrency_ceiling' => 1,
                'observed_at' => '2026-10-17 16:00:00',
            ]);
            $workers[] = ['actor' => (string) $user->id, 'spoke' => $machine->id];
        }
        $task = WorkTask::query()->create([
            'team_id' => $team->id,
            'title' => 'One deterministic winner',
            'required_capabilities' => ['php'],
            'timebox_minutes' => 30,
            'creator_actor' => 'race-creator',
        ]);
        $item = $task->items()->create(['role' => 'builder']);

        $results = runWorkClaimProcesses('machine-routing', $team->id, $workers, [1, 1], $database['connection']);
        expect(array_merge(...$results))->toBe([$item->id])
            ->and($item->fresh()->claimant_spoke_id)->toBe($workers[0]['spoke']);
    } finally {
        cleanupWorkRaceDatabase($database);
    }
});

test('simultaneous production drains preserve the deterministic machine winner on PostgreSQL', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Dedicated PostgreSQL production machine drain race proof.');
    }

    $database = provisionWorkRaceDatabase();

    try {
        $now = CarbonImmutable::parse('2026-10-17 16:00:00 UTC');
        $team = Team::query()->create([
            'name' => 'PostgreSQL deterministic routing race',
            'slug' => 'postgresql-deterministic-routing-race',
            'pool_work' => true,
        ]);
        $workers = [];
        foreach (range(1, 2) as $index) {
            $user = new User;
            $user->forceFill([
                'name' => "Production drain worker {$index}",
                'email' => "production-drain-worker-{$index}@example.test",
                'password' => bcrypt('test-created-password'),
                'role' => UserRole::Member->value,
                'status' => 'active',
            ])->save();
            $credential = (string) Str::uuid7();
            $machine = Spoke::query()->create([
                'actor_id' => (string) $user->id,
                'credential_id' => $credential,
                'credential_backed' => true,
                'last_polled_at' => $now,
                'probe_status' => 'green',
            ]);
            MachineTeamWorkSetting::query()->create([
                'spoke_id' => $machine->id,
                'team_id' => $team->id,
                'enabled' => true,
                'roles' => ['builder'],
                'capabilities' => ['php'],
                'role_providers' => ['builder' => 'codex'],
                'concurrency_ceiling' => 1,
                'updated_by_actor' => (string) $user->id,
            ]);
            expect(resolve(WorkDrainService::class)->claim(
                $team->id,
                (string) $user->id,
                $credential,
                1,
                ['builder'],
                ['php'],
                ['builder' => 'codex'],
                0,
                $now,
            ))->toBe([]);
            $workers[] = ['actor' => (string) $user->id, 'spoke' => $machine->id, 'credential' => $credential];
        }
        $task = WorkTask::query()->create([
            'team_id' => $team->id,
            'title' => 'One PostgreSQL production drain winner',
            'required_capabilities' => ['php'],
            'timebox_minutes' => 30,
            'creator_actor' => 'race-creator',
        ]);
        $item = $task->items()->create(['role' => 'builder']);

        $results = runMachineDrainProcesses($team->id, $workers, $database['connection']);

        expect(array_merge(...$results))->toBe([$item->id])
            ->and($item->fresh()->claimant_spoke_id)->toBe($workers[0]['spoke'])
            ->and(WorkItem::query()->whereKey($item->id)->where('state', WorkItemState::Claimed)->count())->toBe(1);
    } finally {
        cleanupWorkRaceDatabase($database);
    }
});

test('simultaneous healthy owner contenders route non-pooled work only to its owner on PostgreSQL', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Dedicated PostgreSQL owner-routing race proof.');
    }

    $database = provisionWorkRaceDatabase();

    try {
        $now = CarbonImmutable::parse('2026-10-17 16:00:00 UTC');
        $team = Team::query()->create([
            'name' => 'PostgreSQL owner routing race',
            'slug' => 'postgresql-owner-routing-race',
            'pool_work' => false,
        ]);
        $workers = [];
        foreach (range(1, 2) as $index) {
            $user = new User;
            $user->forceFill([
                'name' => "Owner routing worker {$index}",
                'email' => "owner-routing-worker-{$index}@example.test",
                'password' => bcrypt('test-created-password'),
                'role' => UserRole::Member->value,
                'status' => 'active',
            ])->save();
            $credential = (string) Str::uuid7();
            $machine = Spoke::query()->create([
                'actor_id' => (string) $user->id,
                'credential_id' => $credential,
                'credential_backed' => true,
                'last_polled_at' => $now,
                'probe_status' => 'green',
            ]);
            MachineTeamWorkSetting::query()->create([
                'spoke_id' => $machine->id,
                'team_id' => $team->id,
                'enabled' => true,
                'roles' => ['builder'],
                'capabilities' => ['php'],
                'role_providers' => ['builder' => 'codex'],
                'concurrency_ceiling' => 1,
                'updated_by_actor' => (string) $user->id,
            ]);
            expect(resolve(WorkDrainService::class)->claim(
                $team->id, (string) $user->id, $credential, 0, ['builder'], ['php'], ['builder' => 'codex'], 0, $now,
            ))->toBe([]);
            $workers[] = ['actor' => (string) $user->id, 'spoke' => $machine->id, 'credential' => $credential];
        }
        $task = WorkTask::query()->create([
            'team_id' => $team->id,
            'title' => 'Owner-only claim',
            'required_capabilities' => ['php'],
            'timebox_minutes' => 30,
            'creator_actor' => $workers[0]['actor'],
            'owner_actor' => $workers[0]['actor'],
        ]);
        $item = $task->items()->create(['role' => 'builder']);

        $results = runMachineDrainProcesses($team->id, $workers, $database['connection']);
        expect($results[0])->toBe([$item->id])
            ->and($results[1])->toBe([])
            ->and($item->fresh()->claimant_spoke_id)->toBe($workers[0]['spoke']);
    } finally {
        cleanupWorkRaceDatabase($database);
    }
});

test('simultaneous P0 root creation admits one active task per owner on PostgreSQL', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Dedicated PostgreSQL active-P0 race proof.');
    }

    $database = provisionWorkRaceDatabase();
    try {
        $teamId = (int) DB::table('teams')->where('is_default', true)->value('id');
        $results = runP0CreationProcesses($teamId, 'concurrent-p0-owner', $database['connection']);
        sort($results);
        expect($results)->toBe(['accepted', 'conflict'])
            ->and(WorkTask::query()->where('owner_actor', 'concurrent-p0-owner')->where('priority', 'P0')->count())->toBe(1);
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

test('retirement and interactive seat acquisition serialize on the active spoke', function (): void {
    $database = provisionWorkRaceDatabase();
    $barrier = storage_path('framework/testing/retirement-seat-barrier-'.Str::lower(Str::random(16)));
    mkdir($barrier, 0777, true);
    $processes = [];

    try {
        $teamId = DB::table('teams')->where('is_default', true)->value('id');
        $actor = 'retirement-seat-actor';
        $spoke = DB::table('spokes')->insertGetId([
            'actor_id' => $actor,
            'credential_id' => (string) Str::uuid7(),
            'credential_backed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $task = (string) Str::uuid7();
        DB::table('work_tasks')->insert([
            'id' => $task,
            'team_id' => $teamId,
            'title' => 'Retirement seat race',
            'failover' => true,
            'required_capabilities' => '[]',
            'timebox_minutes' => 30,
            'creator_actor' => $actor,
            'owner_actor' => $actor,
            'priority' => 'P2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['seat', 'retire'] as $mode) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/spoke-retirement-seat-worker.php'),
                $mode,
                $barrier,
                $actor,
                (string) $spoke,
                $task,
            ], base_path(), workRaceProcessEnvironment($database['connection']), null, 90);
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 30;
        while (count(glob($barrier.'/ready-*') ?: []) !== 2) {
            foreach ($processes as $process) {
                if ($process->isTerminated()) {
                    throw new RuntimeException('Retirement race worker failed before release: '.$process->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Retirement race workers did not reach the barrier.');
            }
            Sleep::usleep(10_000);
        }
        touch($barrier.'/release');
        $results = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        DB::purge('work_race');
        $isRetired = DB::table('spokes')->where('id', $spoke)->whereNotNull('retired_at')->exists();
        $hasLiveSeat = DB::table('work_orchestrator_seats')
            ->where('holder_spoke_id', $spoke)
            ->where('lease_until', '>', CarbonImmutable::parse('2026-10-19 12:00:00 UTC'))
            ->exists();
        expect(collect($results)->pluck('status')->filter(fn (string $status): bool => $status === 'refused'))->toHaveCount(1)
            ->and($isRetired)->toBe(! $hasLiveSeat)
            ->and($isRetired || $hasLiveSeat)->toBeTrue()
            ->and(DB::table('work_orchestrator_seats')->whereNotNull('active_item_id')->count())->toBe(0);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
        cleanupWorkRaceDatabase($database);
    }
});

test('retirement waits for an in-flight signed action holding the active spoke lock', function (): void {
    $database = provisionWorkRaceDatabase();
    $barrier = storage_path('framework/testing/retirement-signed-barrier-'.Str::lower(Str::random(16)));
    mkdir($barrier, 0777, true);
    $processes = [];

    try {
        $teamId = DB::table('teams')->where('is_default', true)->value('id');
        $actor = 'retirement-signed-actor';
        $now = CarbonImmutable::parse('2026-10-19 12:00:00 UTC');
        $spoke = DB::table('spokes')->insertGetId([
            'actor_id' => $actor,
            'credential_id' => (string) Str::uuid7(),
            'credential_backed' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $task = (string) Str::uuid7();
        DB::table('work_tasks')->insert([
            'id' => $task,
            'team_id' => $teamId,
            'title' => 'Retirement signed-action race',
            'failover' => true,
            'required_capabilities' => '[]',
            'timebox_minutes' => 30,
            'creator_actor' => $actor,
            'owner_actor' => $actor,
            'priority' => 'P2',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $item = (string) Str::uuid7();
        DB::table('work_items')->insert([
            'id' => $item,
            'work_task_id' => $task,
            'role' => 'builder',
            'state' => WorkItemState::Routed->value,
            'fence' => 1,
            'attempts' => 0,
            'claimant_actor' => $actor,
            'claimant_spoke_id' => $spoke,
            'claimed_at' => $now->subMinutes(10),
            'lease_until' => $now->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $arguments = fn (string $mode): array => [
            PHP_BINARY,
            base_path('tests/Support/spoke-retirement-signed-worker.php'),
            $mode,
            $barrier,
            $actor,
            (string) $spoke,
            $item,
        ];
        $action = new Process($arguments('action'), base_path(), workRaceProcessEnvironment($database['connection']), null, 90);
        $action->start();
        $processes[] = $action;

        $deadline = microtime(true) + 30;
        while (! file_exists($barrier.'/action-locked')) {
            if ($action->isTerminated()) {
                throw new RuntimeException('Signed action failed before locking the spoke: '.$action->getErrorOutput());
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Signed action did not lock the spoke.');
            }
            Sleep::usleep(10_000);
        }

        $retire = new Process($arguments('retire'), base_path(), workRaceProcessEnvironment($database['connection']), null, 90);
        $retire->start();
        $processes[] = $retire;
        while (! file_exists($barrier.'/retire-attempting')) {
            if ($retire->isTerminated()) {
                throw new RuntimeException('Retirement failed before attempting its lock: '.$retire->getErrorOutput());
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Retirement did not attempt its lock.');
            }
            Sleep::usleep(10_000);
        }

        Sleep::usleep(250_000);
        expect($retire->isRunning())->toBeTrue('Retirement crossed the active spoke lock.');
        touch($barrier.'/release-action');

        $results = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        expect(collect($results)->pluck('status')->all())->toBe(['action_completed', 'retired'])
            ->and(collect($results)->pluck('driver')->unique()->all())->toBe([$database['connection']['driver']])
            ->and(DB::table('spokes')->where('id', $spoke)->whereNotNull('retired_at')->exists())->toBeTrue()
            ->and(DB::table('work_milestones')->where('work_item_id', $item)->count())->toBe(1)
            ->and(DB::table('work_item_transitions')->where('work_item_id', $item)->count())->toBe(0)
            ->and(DB::table('work_items')->where('id', $item)->value('state'))->toBe(WorkItemState::Routed->value);
    } finally {
        if (is_dir($barrier) && ! file_exists($barrier.'/release-action')) {
            touch($barrier.'/release-action');
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
        cleanupWorkRaceDatabase($database);
    }
});

test('orchestration parent slot serializes competing drains and concurrent circuit breaker projections', function (): void {
    $database = provisionWorkRaceDatabase();
    config(['capstan.features.work' => true]);
    Feature::flushCache();

    try {
        $seed = seedWorkRaceQueue(1);
        // No initial items: ten source transactions compete to create and claim the turn.
        DB::table('work_items')->delete();
        $results = runWorkClaimProcesses('orchestration', $seed['team_id'], $seed['workers'], array_fill(0, 10, 25), $database['connection']);
        $ids = array_merge(...$results);
        expect($ids)->toHaveCount(1)->and(array_unique($ids))->toHaveCount(1)
            ->and(DB::table('work_items')->whereIn('state', ['claimed', 'delivered', 'routed'])->count())->toBe(1)
            ->and(DB::table('work_orchestration_events')->count())->toBe(10)
            ->and(DB::table('work_orchestrator_seats')->count())->toBe(1)
            ->and(DB::table('work_orchestrator_seats')->value('active_item_id'))->toBe($ids[0]);

        // Isolate a second parent at its completed-turn limit. Concurrent facts must
        // create exactly one addressed human item, and none of the drains may claim it.
        $budget = seedWorkRaceQueue(1);
        $parent = WorkTask::query()->where('team_id', $budget['team_id'])->sole();
        $parent->items()->delete();
        WorkOrchestratorSeat::query()->create(['work_task_id' => $parent->id, 'turns_completed' => 1]);
        $budgetResults = runWorkClaimProcesses('orchestration-budget', $budget['team_id'], $budget['workers'], array_fill(0, 10, 25), $database['connection']);
        expect(array_merge(...$budgetResults))->toBe([])
            ->and($parent->items()->where('role', 'human')->count())->toBe(1)
            ->and($parent->items()->where('role', 'human')->sole()->addressed_actor)->toBe('race-test')
            ->and(DB::table('work_escalations')->where('work_task_id', $parent->id)->count())->toBe(1)
            ->and(DB::table('work_orchestration_events')->where('work_task_id', $parent->id)->count())->toBe(10);

        $childBudget = seedWorkRaceQueue(1);
        $childParent = WorkTask::query()->where('team_id', $childBudget['team_id'])->sole();
        $childParent->items()->delete();
        $child = WorkTask::query()->create([
            'team_id' => $childBudget['team_id'], 'parent_task_id' => $childParent->id,
            'title' => 'Concurrent child accounting', 'required_capabilities' => ['php'],
            'timebox_minutes' => 30, 'creator_actor' => 'race-test',
        ]);
        resolve(WorkOrchestrationService::class)->event($childParent, 'start', 'test_fact');
        $owner = $childBudget['workers'][0];
        $turn = resolve(WorkClaimService::class)->claim($childBudget['team_id'], $owner['actor'], $owner['spoke'], ['orchestrator'], ['php'], 1, CarbonImmutable::parse('2026-10-17 16:00:00 UTC'), 300)[0];
        runWorkClaimProcesses('orchestration-child-budget', $childBudget['team_id'], $childBudget['workers'], array_fill(0, 10, 25), $database['connection']);
        expect($child->items()->count())->toBe(1)
            ->and($turn->fresh()->state)->toBe(WorkItemState::Cancelled)
            ->and(DB::table('work_escalations')->where('work_task_id', $childParent->id)->count())->toBe(1)
            ->and($childParent->items()->where('role', 'human')->count())->toBe(1)
            ->and(DB::table('work_orchestrator_seats')->where('work_task_id', $childParent->id)->value('children_created'))->toBe(1);
    } finally {
        cleanupWorkRaceDatabase($database);
    }
});
