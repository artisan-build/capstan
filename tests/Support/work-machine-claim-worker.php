<?php

use App\Work\WorkDrainService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 7) {
    fwrite(STDERR, "Expected worker, barrier, team, actor, credential, and capacity.\n");
    exit(2);
}

[, $worker, $barrier, $teamId, $actorId, $credentialId, $capacity] = $argv;

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA busy_timeout = 15000');
        DB::statement('PRAGMA journal_mode = WAL');
    }

    file_put_contents($barrier.'/ready-'.$worker, 'ready');
    $deadline = microtime(true) + 30;

    while (! file_exists($barrier.'/release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Barrier release timed out.');
        }

        Sleep::usleep(10_000);
    }

    $items = resolve(WorkDrainService::class)->claim(
        (int) $teamId,
        $actorId,
        $credentialId,
        (int) $capacity,
        ['builder'],
        ['php'],
        ['builder' => 'codex'],
        0,
        CarbonImmutable::parse('2026-10-17 16:00:00 UTC'),
    );

    fwrite(STDOUT, json_encode([
        'worker' => (int) $worker,
        'ids' => array_map(static fn ($item): string => $item->id, $items),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, substr($exception::class.': '.$exception->getMessage(), 0, 2000)."\n");
    exit(1);
}
