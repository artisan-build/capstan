<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Models\Spoke;
use ArtisanBuild\BuiltForCloud\Credential;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('two real replacement processes leave one live credential on the original spoke', function (): void {
    expect(app()->environment('testing'))->toBeTrue();
    $original = DB::getDefaultConnection();
    $database = storage_path('framework/testing/capstan_spoke_replace_'.Str::lower(Str::random(12)).'.sqlite');
    $barrier = storage_path('framework/testing/spoke-replace-barrier-'.Str::lower(Str::random(12)));
    touch($database);
    mkdir($barrier, 0777, true);
    $appKey = 'base64:'.base64_encode(str_repeat('c', 32));
    $serverId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $connection = array_replace(config('database.connections.sqlite'), [
        'database' => $database,
        'busy_timeout' => 15_000,
        'journal_mode' => 'WAL',
        'synchronous' => 'NORMAL',
    ]);
    $processes = [];

    try {
        config([
            'app.key' => $appKey,
            'capstan.postmaster.server_id' => $serverId,
            'database.connections.spoke_replace_race' => $connection,
        ]);
        DB::setDefaultConnection('spoke_replace_race');
        DB::purge('spoke_replace_race');
        Artisan::call('migrate:fresh', ['--database' => 'spoke_replace_race', '--force' => true]);
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
        $baseEnvironment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => $appKey,
            'CAPSTAN_SERVER_ID' => $serverId,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
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
        expect(collect($results)->pluck('conflict')->sort()->values()->all())->toBe([false, true])
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
        DB::purge('spoke_replace_race');
        DB::setDefaultConnection($original);
        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
        foreach ([$database, $database.'-shm', $database.'-wal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});
