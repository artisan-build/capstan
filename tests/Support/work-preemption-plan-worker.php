<?php

use App\Enums\WorkMilestoneKind;
use App\Work\WorkPriorityService;
use App\Work\WorkWorkerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 9) {
    fwrite(STDERR, "Expected mode, barrier, team, root, actor, token, victim and fence.\n");
    exit(2);
}

[, $mode, $barrier, $teamId, $rootId, $actorId, $token, $victimId, $fence] = $argv;

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if ($mode === 'writer') {
        DB::transaction(function () use ($barrier, $victimId, $fence): void {
            resolve(WorkWorkerService::class)->milestone(
                $victimId,
                (int) $fence,
                WorkMilestoneKind::Note,
                ['summary' => 'Concurrent plan evidence'],
            );
            touch($barrier.'/writer-locked');
            $deadline = microtime(true) + 30;
            while (! file_exists($barrier.'/release-writer')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Writer release timed out.');
                }
                Sleep::usleep(10_000);
            }
        });
        fwrite(STDOUT, json_encode(['status' => 'committed'], JSON_THROW_ON_ERROR));

        exit(0);
    }

    if ($mode !== 'confirm') {
        throw new RuntimeException('Unknown preemption-plan worker mode.');
    }

    $pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    file_put_contents($barrier.'/confirm-pid', (string) $pid);
    $result = resolve(WorkPriorityService::class)->change([
        'team_id' => (int) $teamId,
        'task_id' => $rootId,
        'priority' => 'P0',
        'confirmation_token' => $token,
        'reason' => 'Concurrent incident',
    ], $actorId, 'postgres-plan-race-confirm');
    touch($barrier.'/confirm-done');
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, substr($exception::class.': '.$exception->getMessage(), 0, 2000)."\n");
    exit(1);
}
