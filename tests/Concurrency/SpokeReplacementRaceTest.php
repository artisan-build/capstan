<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Models\MachineTeamWorkSetting;
use App\Models\Spoke;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\Actions\OffboardSubject;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\OffboardOptions;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/** @return array{connection: array<string, mixed>, database: string, sqlitePath: string|null, original: string} */
function provisionSpokeReplacementRaceDatabase(): array
{
    $original = DB::getDefaultConnection();
    /** @var array<string, mixed> $base */
    $base = config("database.connections.$original");
    $driver = $base['driver'] ?? null;
    expect($driver)->toBeIn(['sqlite', 'pgsql']);
    $name = 'capstan_spoke_replace_'.Str::lower(Str::random(12));
    $sqlitePath = null;

    if ($driver === 'sqlite') {
        $sqlitePath = storage_path("framework/testing/$name.sqlite");
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
        $database = $name;
        DB::connection($original)->statement('CREATE DATABASE "'.$database.'"');
        $connection = array_replace($base, ['database' => $database]);
    }

    config(['database.connections.spoke_replace_race' => $connection]);
    DB::setDefaultConnection('spoke_replace_race');
    DB::purge('spoke_replace_race');
    Artisan::call('migrate:fresh', ['--database' => 'spoke_replace_race', '--force' => true]);

    return compact('connection', 'database', 'sqlitePath', 'original');
}

/** @param array{connection: array<string, mixed>, database: string, sqlitePath: string|null, original: string} $database */
function cleanupSpokeReplacementRaceDatabase(array $database): void
{
    DB::purge('spoke_replace_race');
    DB::setDefaultConnection($database['original']);

    if ($database['sqlitePath'] !== null) {
        foreach ([$database['sqlitePath'], $database['sqlitePath'].'-shm', $database['sqlitePath'].'-wal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return;
    }

    $admin = DB::connection($database['original']);
    $admin->statement('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()', [$database['database']]);
    $admin->statement('DROP DATABASE "'.$database['database'].'"');
}

/** @param array<string, mixed> $connection @return array<string, string> */
function spokeReplacementRaceEnvironment(array $connection): array
{
    $environment = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => (string) $connection['driver'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_URL' => '',
    ];
    foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $name) {
        if (isset($connection[$key])) {
            $environment[$name] = (string) $connection[$key];
        }
    }

    return $environment;
}

/** @param list<array<string, string>> $workerEnvironments
 * @return list<array<string, mixed>>
 */
function runSpokeReplacementWorkers(string $barrier, array $baseEnvironment, array $workerEnvironments): array
{
    $processes = [];
    foreach ($workerEnvironments as $index => $environment) {
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/spoke-replacement-worker.php'),
            (string) $index,
            $barrier,
        ], base_path(), [...$baseEnvironment, ...$environment], null, 90);
        $process->start();
        $processes[] = $process;
    }

    try {
        $deadline = microtime(true) + 30;
        while (count(glob($barrier.'/ready-*') ?: []) !== count($processes)) {
            foreach ($processes as $process) {
                if ($process->isTerminated()) {
                    throw new RuntimeException('Replacement worker failed before release: '.$process->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Replacement workers did not reach the barrier.');
            }
            Sleep::usleep(10_000);
        }

        touch($barrier.'/release');
        $results = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
}

test('two real replacement processes leave one live credential on the original spoke', function (): void {
    expect(app()->environment('testing'))->toBeTrue();
    $database = provisionSpokeReplacementRaceDatabase();
    $barrier = storage_path('framework/testing/spoke-replace-barrier-'.Str::lower(Str::random(12)));
    mkdir($barrier, 0777, true);
    $appKey = 'base64:'.base64_encode(str_repeat('c', 32));
    $serverId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $processes = [];

    try {
        config([
            'app.key' => $appKey,
            'capstan.postmaster.server_id' => $serverId,
        ]);
        $user = capstanUser();
        $old = capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL);
        $new = [
            capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL),
            capstanBoundBearer($user, CapstanCredentialDeclaration::POSTMASTER_POLL),
        ];
        $spoke = Spoke::query()->create([
            'actor_id' => (string) $user->id,
            'credential_id' => $old['credential']->id,
        ]);
        $baseEnvironment = [...spokeReplacementRaceEnvironment($database['connection']),
            'APP_KEY' => $appKey,
            'CAPSTAN_SERVER_ID' => $serverId,
            'REPLACEMENT_ACTOR_ID' => (string) $user->id,
            'REPLACEMENT_OLD_TOKEN' => $old['token'],
        ];

        foreach ($new as $index => $issued) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/spoke-replacement-worker.php'),
                (string) $index,
                $barrier,
            ], base_path(), [
                ...$baseEnvironment,
                'REPLACEMENT_NEW_CREDENTIAL_ID' => $issued['credential']->id,
            ], null, 90);
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 30;
        while (count(glob($barrier.'/ready-*') ?: []) !== 2) {
            foreach ($processes as $process) {
                if ($process->isTerminated()) {
                    throw new RuntimeException('Replacement worker failed before release: '.$process->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Replacement workers did not reach the barrier.');
            }
            Sleep::usleep(10_000);
        }

        touch($barrier.'/release');
        $results = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        DB::purge('spoke_replace_race');
        $winnerCredential = $spoke->fresh()?->credential_id;
        $newCredentialIds = array_map(fn (array $issued): string => $issued['credential']->id, $new);
        expect(collect($results)->pluck('driver')->unique()->values()->all())->toBe([$database['connection']['driver']])
            ->and(collect($results)->pluck('conflict')->sort()->values()->all())->toBe([false, true])
            ->and($spoke->fresh()?->id)->toBe($spoke->id)
            ->and($winnerCredential)->toBeIn($newCredentialIds)
            ->and(Credential::query()->whereIn('id', $newCredentialIds)->whereNull('revoked_at')->count())->toBe(1)
            ->and($old['credential']->fresh()?->revoked_at)->not->toBeNull()
            ->and(Spoke::query()->count())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        cleanupSpokeReplacementRaceDatabase($database);
        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
    }
});

test('two teammates racing one offboarded proof produce one preserved-row winner and one fresh row', function (): void {
    expect(app()->environment('testing'))->toBeTrue();
    $database = provisionSpokeReplacementRaceDatabase();
    $barrier = storage_path('framework/testing/spoke-orphan-replace-barrier-'.Str::lower(Str::random(12)));
    mkdir($barrier, 0777, true);
    $appKey = 'base64:'.base64_encode(str_repeat('o', 32));
    $serverId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    try {
        config(['app.key' => $appKey, 'capstan.postmaster.server_id' => $serverId]);
        $oldOwner = capstanUser();
        $teammates = [capstanUser(), capstanUser()];
        $old = capstanBoundBearer($oldOwner, CapstanCredentialDeclaration::MACHINE);
        $new = array_map(fn ($user): array => capstanBoundBearer($user, CapstanCredentialDeclaration::MACHINE), $teammates);
        $spoke = Spoke::query()->create([
            'actor_id' => (string) $oldOwner->id,
            'credential_id' => $old['credential']->id,
        ]);
        MachineTeamWorkSetting::query()->create([
            'spoke_id' => $spoke->id, 'team_id' => Team::default()->id, 'enabled' => true,
            'roles' => ['builder'], 'capabilities' => [], 'role_providers' => [],
            'concurrency_ceiling' => 1, 'updated_by_actor' => (string) $oldOwner->id,
        ]);
        resolve(OffboardSubject::class)(new OffboardOptions(SubjectType::UserPrincipal, 'capstan-user:'.$oldOwner->id));
        Artisan::call('postmaster:retire-dead-spokes', ['--local' => true]);
        expect($spoke->fresh()?->orphaned_at)->not->toBeNull();

        $baseEnvironment = [
            ...spokeReplacementRaceEnvironment($database['connection']),
            'APP_KEY' => $appKey,
            'CAPSTAN_SERVER_ID' => $serverId,
            'REPLACEMENT_MODE' => 'orphan',
            'REPLACEMENT_OLD_TOKEN' => $old['token'],
        ];
        $workers = [];
        foreach ($teammates as $index => $teammate) {
            $workers[] = [
                'REPLACEMENT_ACTOR_ID' => (string) $teammate->id,
                'REPLACEMENT_NEW_CREDENTIAL_ID' => $new[$index]['credential']->id,
            ];
        }
        $results = runSpokeReplacementWorkers($barrier, $baseEnvironment, $workers);

        DB::purge('spoke_replace_race');
        $preserved = $spoke->fresh();
        $newIds = array_column(array_column($new, 'credential'), 'id');
        expect(collect($results)->pluck('replaced')->sort()->values()->all())->toBe([false, true])
            ->and(collect($results)->pluck('conflict')->unique()->values()->all())->toBe([false])
            ->and($preserved?->id)->toBe($spoke->id)
            ->and($preserved?->actor_id)->toBeIn(array_map(fn ($user): string => (string) $user->id, $teammates))
            ->and($preserved?->credential_id)->toBeIn($newIds)
            ->and($preserved?->orphaned_at)->toBeNull()
            ->and($preserved?->retired_at)->toBeNull()
            ->and(Spoke::query()->count())->toBe(2)
            ->and(Spoke::query()->whereIn('credential_id', $newIds)->count())->toBe(2)
            ->and($old['credential']->fresh()?->revoked_at)->not->toBeNull();
    } finally {
        cleanupSpokeReplacementRaceDatabase($database);
        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
    }
});

test('an explicit revoke racing orphan proof never transfers the preserved row', function (): void {
    expect(app()->environment('testing'))->toBeTrue();
    $database = provisionSpokeReplacementRaceDatabase();
    $barrier = storage_path('framework/testing/spoke-revoke-transfer-barrier-'.Str::lower(Str::random(12)));
    mkdir($barrier, 0777, true);
    $appKey = 'base64:'.base64_encode(str_repeat('v', 32));
    $serverId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    try {
        config(['app.key' => $appKey, 'capstan.postmaster.server_id' => $serverId]);
        $oldOwner = capstanUser();
        $newOwner = capstanUser();
        $old = capstanBoundBearer($oldOwner, CapstanCredentialDeclaration::MACHINE);
        $new = capstanBoundBearer($newOwner, CapstanCredentialDeclaration::MACHINE);
        $spoke = Spoke::query()->create([
            'actor_id' => (string) $oldOwner->id,
            'credential_id' => $old['credential']->id,
            'orphaned_at' => now(),
        ]);
        MachineTeamWorkSetting::query()->create([
            'spoke_id' => $spoke->id, 'team_id' => Team::default()->id, 'enabled' => true,
            'roles' => ['builder'], 'capabilities' => [], 'role_providers' => [],
            'concurrency_ceiling' => 1, 'updated_by_actor' => (string) $oldOwner->id,
        ]);

        $baseEnvironment = [
            ...spokeReplacementRaceEnvironment($database['connection']),
            'APP_KEY' => $appKey,
            'CAPSTAN_SERVER_ID' => $serverId,
            'REPLACEMENT_OLD_TOKEN' => $old['token'],
            'REPLACEMENT_OLD_CREDENTIAL_ID' => $old['credential']->id,
        ];
        $results = runSpokeReplacementWorkers($barrier, $baseEnvironment, [[
            'REPLACEMENT_MODE' => 'orphan',
            'REPLACEMENT_ACTOR_ID' => (string) $newOwner->id,
            'REPLACEMENT_NEW_CREDENTIAL_ID' => $new['credential']->id,
        ], [
            'REPLACEMENT_MODE' => 'revoke',
            'REPLACEMENT_ACTOR_ID' => (string) $oldOwner->id,
        ]]);

        DB::purge('spoke_replace_race');
        $unchanged = $spoke->fresh();
        $transfer = collect($results)->firstWhere('replaced', false);
        expect($transfer)->toBeArray()
            ->and($unchanged?->id)->toBe($spoke->id)
            ->and($unchanged?->actor_id)->toBe((string) $oldOwner->id)
            ->and($unchanged?->credential_id)->toBe($old['credential']->id)
            ->and($unchanged?->orphaned_at)->not->toBeNull()
            ->and($unchanged?->retired_at)->toBeNull()
            ->and(Spoke::query()->where('credential_id', $new['credential']->id)->count())->toBe(1)
            ->and($old['credential']->fresh()?->revoked_at)->not->toBeNull();
    } finally {
        cleanupSpokeReplacementRaceDatabase($database);
        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
    }
});
