<?php

use App\Http\ApiErrorException;
use App\Work\WorkDrainService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 8) {
    fwrite(STDERR, "Expected mode, barrier, team, item, actor, credential and fence.\n");
    exit(2);
}

[, $mode, $barrier, $teamId, $itemId, $actorId, $credentialId, $fence] = $argv;
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDriverName() === 'sqlite') {
    DB::statement('PRAGMA busy_timeout = 15000');
    DB::statement('PRAGMA journal_mode = WAL');
}

$pause = static function () use ($barrier): void {
    file_put_contents($barrier.'/ready', 'ready');
    $deadline = microtime(true) + 30;

    while (! file_exists($barrier.'/release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Barrier release timed out.');
        }

        Sleep::usleep(10_000);
    }
};
$drain = resolve(WorkDrainService::class);
$now = CarbonImmutable::parse('2026-10-17 16:00:30 UTC');

try {
    if ($mode === 'transition') {
        $item = $drain->transition(
            (int) $teamId,
            $actorId,
            $credentialId,
            $itemId,
            (int) $fence,
            'delivered',
            $now,
            $pause,
        );
        $result = ['status' => 'transitioned', 'state' => $item->state->value];
    } else {
        $results = $drain->renew(
            (int) $teamId,
            $actorId,
            $credentialId,
            [['item_id' => $itemId, 'fence' => (int) $fence]],
            $now,
            $pause,
        );
        $result = ['status' => $results[0]['status']];
    }
} catch (ApiErrorException $exception) {
    $result = [
        'status' => 'rejected',
        'http_status' => $exception->status,
        'code' => $exception->errorCode,
    ];
}

fwrite(STDOUT, 'WORK_FENCE_RESULT='.json_encode($result, JSON_THROW_ON_ERROR)."\n");
