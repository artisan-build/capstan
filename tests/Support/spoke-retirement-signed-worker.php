<?php

use App\Enums\WorkMilestoneKind;
use App\Postmaster\RetireSpoke;
use App\Work\WorkWorkerService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 6) {
    fwrite(STDERR, "Expected mode, barrier, actor, spoke and item.\n");
    exit(2);
}

[, $mode, $barrier, $actor, $spoke, $item] = $argv;

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA busy_timeout = 15000');
        DB::statement('PRAGMA journal_mode = WAL');
    }

    $attempt = 0;
    beginning:
    try {
        if ($mode === 'action') {
            resolve(WorkWorkerService::class)->milestone(
                $item,
                1,
                WorkMilestoneKind::Note,
                ['summary' => 'serialized signed action'],
                function () use ($barrier): void {
                    file_put_contents($barrier.'/action-locked', 'locked');
                    $deadline = microtime(true) + 30;
                    while (! file_exists($barrier.'/release-action')) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Signed action barrier timed out.');
                        }
                        Sleep::usleep(10_000);
                    }
                },
            );
            $status = 'action_completed';
        } else {
            file_put_contents($barrier.'/retire-attempting', 'attempting');
            resolve(RetireSpoke::class)->handle(
                (int) $spoke,
                $actor,
                CarbonImmutable::parse('2026-10-19 12:00:00 UTC'),
            );
            $status = 'retired';
        }
    } catch (HttpException) {
        $status = 'refused';
    } catch (QueryException $exception) {
        $attempt++;
        if ($attempt < 50 && str_contains(strtolower($exception->getMessage()), 'database is locked')) {
            Sleep::usleep(random_int(1_000, 20_000));
            goto beginning;
        }
        throw $exception;
    }

    fwrite(STDOUT, json_encode([
        'mode' => $mode,
        'status' => $status,
        'driver' => DB::getDriverName(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, substr($exception::class.': '.$exception->getMessage(), 0, 2000)."\n");
    exit(1);
}
