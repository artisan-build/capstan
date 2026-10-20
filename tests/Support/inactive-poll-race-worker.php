<?php

use App\Postmaster\MarkInactiveMachines;
use App\Support\BoundCredentialSpokeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 3) {
    fwrite(STDERR, "Expected mode and barrier.\n");
    exit(2);
}

[, $mode, $barrier] = $argv;

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException('The inactivity/poll race worker requires PostgreSQL.');
    }

    $now = CarbonImmutable::parse((string) getenv('INACTIVE_RACE_NOW'));
    if ($mode === 'poll') {
        DB::beginTransaction();
        try {
            $resolver = resolve(BoundCredentialSpokeResolver::class);
            $spoke = $resolver->resolve(
                (string) getenv('INACTIVE_RACE_ACTOR_ID'),
                (string) getenv('INACTIVE_RACE_CREDENTIAL_ID'),
                $now,
            );
            file_put_contents($barrier.'/poll-locked', 'locked');
            $deadline = microtime(true) + 30;
            while (! file_exists($barrier.'/release-poll')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Poll release barrier timed out.');
                }
                Sleep::usleep(10_000);
            }
            $resolver->heartbeat($spoke, $now);
            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        fwrite(STDOUT, json_encode(['mode' => 'poll', 'committed' => true], JSON_THROW_ON_ERROR));
        exit(0);
    }

    if ($mode !== 'sweep') {
        throw new RuntimeException('Unknown inactivity/poll race mode.');
    }

    DB::listen(function (QueryExecuted $query) use ($barrier): void {
        $sql = strtolower($query->sql);
        if (! file_exists($barrier.'/sweep-selected')
            && str_contains($sql, 'select')
            && str_contains($sql, 'from "spokes"')
            && str_contains($sql, 'last_polled_at')
            && ! str_contains($sql, 'for update')) {
            file_put_contents($barrier.'/sweep-selected', 'selected');
        }
    });
    $count = (resolve(MarkInactiveMachines::class))($now);
    fwrite(STDOUT, json_encode(['mode' => 'sweep', 'count' => $count], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, substr($exception::class.': '.$exception->getMessage(), 0, 2000)."\n");
    exit(1);
}
