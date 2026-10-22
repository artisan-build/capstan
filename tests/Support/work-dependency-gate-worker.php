<?php

use App\Models\WorkItem;
use App\Work\WorkClaimService;
use App\Work\WorkToolService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 6) {
    fwrite(STDERR, "Expected mode, barrier, team, actor and target.\n");
    exit(2);
}

[, $mode, $barrier, $teamId, $actorId, $target] = $argv;

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA busy_timeout = 15000');
    }

    if ($mode === 'claim') {
        $items = resolve(WorkClaimService::class)->claim(
            (int) $teamId,
            $actorId,
            (int) $target,
            ['builder'],
            [],
            1,
            CarbonImmutable::parse('2026-10-22 12:00:00 UTC'),
            300,
            afterDependencyGate: function () use ($barrier): void {
                touch($barrier.'/claim-gated');
                $deadline = microtime(true) + 30;
                while (! file_exists($barrier.'/release-claim')) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Claim gate release timed out.');
                    }
                    Sleep::usleep(10_000);
                }
            },
        );
        fwrite(STDOUT, json_encode([
            'status' => 'claimed',
            'ids' => array_map(fn (WorkItem $item): string => $item->id, $items),
        ], JSON_THROW_ON_ERROR));

        exit(0);
    }

    if ($mode !== 'invalidate') {
        throw new RuntimeException('Unknown dependency gate worker mode.');
    }

    touch($barrier.'/invalidate-attempting');
    $result = resolve(WorkToolService::class)->write('cancel_task', [
        'team_id' => (int) $teamId,
        'task_id' => $target,
    ], $actorId);
    touch($barrier.'/invalidate-done');
    fwrite(STDOUT, json_encode([
        'status' => 'invalidated',
        'cancelled_at' => $result['task']['cancelled_at'] ?? null,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, substr($exception::class.': '.$exception->getMessage(), 0, 2000)."\n");
    exit(1);
}
