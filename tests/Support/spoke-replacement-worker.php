<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Postmaster\ReplaceSpokeCredential;
use ArtisanBuild\BuiltForCloud\BoundBearerCredential;
use ArtisanBuild\BuiltForCloud\BoundBearerCredentialAuthenticator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
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
    DB::statement('PRAGMA busy_timeout = 15000');
    DB::statement('PRAGMA journal_mode = WAL');
    $actorId = (string) getenv('REPLACEMENT_ACTOR_ID');
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

    file_put_contents($barrier.'/ready-'.$worker, 'ready');
    $deadline = microtime(true) + 30;

    while (! file_exists($barrier.'/release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Replacement race barrier timed out.');
        }

        Sleep::usleep(10_000);
    }

    $result = resolve(ReplaceSpokeCredential::class)->handle(
        $actorId,
        (string) getenv('REPLACEMENT_NEW_CREDENTIAL_ID'),
        $oldCredential,
        CarbonImmutable::now(),
    );
    fwrite(STDOUT, json_encode([
        'worker' => (int) $worker,
        'conflict' => $result['conflict'],
        'spoke_id' => $result['spoke']?->id,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, substr($exception::class.': '.$exception->getMessage(), 0, 2000)."\n");
    exit(1);
}
