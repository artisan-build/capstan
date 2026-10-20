<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Postmaster\ReplaceSpokeCredential;
use ArtisanBuild\BuiltForCloud\Actions\RevokeCredential;
use ArtisanBuild\BuiltForCloud\BoundBearerCredential;
use ArtisanBuild\BuiltForCloud\BoundBearerCredentialAuthenticator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 3) {
    fwrite(STDERR, "Expected worker and barrier.\n");
    exit(2);
}

[, $worker, $barrier] = $argv;

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $busyTimeout = null;
    $configureSqlite = static function (): int {
        DB::statement('PRAGMA busy_timeout = 60000');
        DB::statement('PRAGMA journal_mode = WAL');
        $busyTimeoutRow = (array) DB::selectOne('PRAGMA busy_timeout');

        return (int) array_values($busyTimeoutRow)[0];
    };
    if (DB::getDriverName() === 'sqlite') {
        $busyTimeout = $configureSqlite();
    }
    $actorId = (string) getenv('REPLACEMENT_ACTOR_ID');
    $mode = (string) (getenv('REPLACEMENT_MODE') ?: 'ordinary');
    $oldCredential = null;

    if ($mode === 'ordinary') {
        $request = Request::create('/api/v1/postmaster/replace', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.(string) getenv('REPLACEMENT_OLD_TOKEN'),
            'HTTP_X_CAPSTAN_ACTOR_ID' => $actorId,
        ]);
        $authenticationLock = fopen($barrier.'/authentication.lock', 'c');
        if ($authenticationLock === false || ! flock($authenticationLock, LOCK_EX)) {
            throw new RuntimeException('Could not acquire the replacement authentication lock.');
        }
        try {
            $oldCredential = resolve(BoundBearerCredentialAuthenticator::class)
                ->authenticate($request, CapstanCredentialDeclaration::POSTMASTER_POLL);
        } finally {
            flock($authenticationLock, LOCK_UN);
            fclose($authenticationLock);
        }

        if (! $oldCredential instanceof BoundBearerCredential) {
            throw new RuntimeException('The old credential did not authenticate before the race.');
        }
    }

    file_put_contents($barrier.'/ready-'.$worker, 'ready');
    $deadline = microtime(true) + 30;

    while (! file_exists($barrier.'/release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Replacement race barrier timed out.');
        }

        Sleep::usleep(10_000);
    }
    if ($mode === 'revoke') {
        for ($attempt = 1; $attempt <= 50; $attempt++) {
            try {
                (resolve(RevokeCredential::class))((string) getenv('REPLACEMENT_OLD_CREDENTIAL_ID'));
                break;
            } catch (DeadlockException|QueryException $exception) {
                if (DB::getDriverName() !== 'sqlite' || ! str_contains(strtolower($exception->getMessage()), 'database is locked') || $attempt === 50) {
                    throw $exception;
                }
                Sleep::usleep(random_int(1_000, 20_000));
            }
        }
        fwrite(STDOUT, json_encode(['worker' => (int) $worker, 'driver' => DB::getDriverName(), 'busy_timeout' => $busyTimeout, 'revoked' => true], JSON_THROW_ON_ERROR));
        exit(0);
    }

    $replace = fn (): array => resolve(ReplaceSpokeCredential::class)->handle(
        $actorId,
        (string) getenv('REPLACEMENT_NEW_CREDENTIAL_ID'),
        $oldCredential,
        CarbonImmutable::now(),
        $mode === 'orphan' ? (string) getenv('REPLACEMENT_OLD_TOKEN') : null,
        $mode === 'orphan' ? CapstanCredentialDeclaration::MACHINE : CapstanCredentialDeclaration::POSTMASTER_POLL,
    );
    $recoveredFromLock = false;
    $result = null;
    for ($attempt = 1; $attempt <= 50; $attempt++) {
        if ($attempt > 1) {
            DB::purge();
            DB::reconnect();
            $busyTimeout = $configureSqlite();
        }
        try {
            $result = $replace();
            $recoveredFromLock = $attempt > 1;
            break;
        } catch (DeadlockException|QueryException $exception) {
            if (DB::getDriverName() !== 'sqlite'
                || ! str_contains(strtolower($exception->getMessage()), 'database is locked')
                || $attempt === 50) {
                throw $exception;
            }
            DB::disconnect();
            Sleep::usleep(random_int(25_000, 100_000));
        }
    }
    if ($result === null) {
        throw new RuntimeException('Replacement race did not produce a result.');
    }
    fwrite(STDOUT, json_encode([
        'worker' => (int) $worker,
        'driver' => DB::getDriverName(),
        'busy_timeout' => $busyTimeout,
        'lock_recovery' => $recoveredFromLock,
        'conflict' => $result['conflict'],
        'replaced' => $result['replaced'],
        'spoke_id' => $result['spoke']?->id,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, substr($exception::class.': '.$exception->getMessage(), 0, 2000)."\n");
    exit(1);
}
