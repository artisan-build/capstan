<?php

use App\Work\WorkClaimService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\Support\UnsafeWorkClaimService;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 8) {
    fwrite(STDERR, "Expected mode, worker, barrier, team, actor, spoke and capacity.\n");
    exit(2);
}

[, $mode, $worker, $barrier, $teamId, $actorId, $spokeId, $capacity] = $argv;
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA busy_timeout = 15000');
        DB::statement('PRAGMA journal_mode = WAL');
    }

    $unsafe = new UnsafeWorkClaimService;
    $selected = $mode === 'unsafe' ? $unsafe->select((int) $teamId, (int) $capacity) : [];
    $ready = $barrier.'/ready-'.$worker;
    $release = $barrier.'/release';
    file_put_contents($ready, 'ready');
    $deadline = microtime(true) + 30;

    while (! file_exists($release)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Barrier release timed out.');
        }

        Sleep::usleep(10_000);
    }

    $now = CarbonImmutable::parse('2026-10-17 16:00:00 UTC');

    if ($mode === 'unsafe') {
        $unsafe->writeWithoutGuard($selected, (int) $spokeId, $actorId, $now);
        $ids = $selected;
    } else {
        $ids = array_map(
            static fn ($item): string => $item->id,
            resolve(WorkClaimService::class)->claim(
                (int) $teamId,
                $actorId,
                (int) $spokeId,
                ['builder'],
                ['php'],
                (int) $capacity,
                $now,
                300,
            ),
        );
    }

    fwrite(STDOUT, json_encode(['worker' => (int) $worker, 'ids' => $ids], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, substr($exception::class.': '.$exception->getMessage(), 0, 2000)."\n");
    exit(1);
}
