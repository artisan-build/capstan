<?php

use App\Enums\WorkPriority;
use App\Models\WorkTask;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 6) {
    fwrite(STDERR, "Expected worker, barrier, team, owner and title.\n");
    exit(2);
}

[, $worker, $barrier, $teamId, $owner, $title] = $argv;

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA busy_timeout = 15000');
    }

    file_put_contents($barrier.'/ready-'.$worker, 'ready');
    $deadline = microtime(true) + 30;
    while (! file_exists($barrier.'/release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Barrier release timed out.');
        }
        Sleep::usleep(10_000);
    }

    try {
        WorkTask::query()->create([
            'team_id' => (int) $teamId,
            'title' => $title,
            'required_capabilities' => [],
            'timebox_minutes' => 30,
            'creator_actor' => $owner,
            'owner_actor' => $owner,
            'priority' => WorkPriority::P0,
        ]);
        $status = 'accepted';
    } catch (QueryException) {
        $status = 'conflict';
    }

    fwrite(STDOUT, json_encode(['worker' => (int) $worker, 'status' => $status], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, substr($exception::class.': '.$exception->getMessage(), 0, 2000)."\n");
    exit(1);
}
