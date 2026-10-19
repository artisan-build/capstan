<?php

use App\Http\ApiErrorException;
use App\Models\WorkTask;
use App\Postmaster\RetireSpoke;
use App\Work\WorkSeatService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 6) {
    fwrite(STDERR, "Expected mode, barrier, actor, spoke and task.\n");
    exit(2);
}

[, $mode, $barrier, $actor, $spoke, $task] = $argv;

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['capstan.features.work' => true]);
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA busy_timeout = 15000');
        DB::statement('PRAGMA journal_mode = WAL');
    }
    file_put_contents($barrier.'/ready-'.$mode, 'ready');
    $deadline = microtime(true) + 30;
    while (! file_exists($barrier.'/release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Retirement race barrier timed out.');
        }
        Sleep::usleep(10_000);
    }

    $attempt = 0;
    beginning:
    try {
        $now = CarbonImmutable::parse('2026-10-19 12:00:00 UTC');
        if ($mode === 'seat') {
            resolve(WorkSeatService::class)->take(
                WorkTask::query()->findOrFail($task),
                $actor,
                (int) $spoke,
                0,
                'retirement-race',
                true,
                $now,
            );
            $status = 'seat_acquired';
        } else {
            resolve(RetireSpoke::class)->handle((int) $spoke, $actor, $now);
            $status = 'retired';
        }
    } catch (DomainException|ApiErrorException) {
        $status = 'refused';
    } catch (QueryException $exception) {
        $attempt++;
        if ($attempt < 50 && str_contains(strtolower($exception->getMessage()), 'database is locked')) {
            Sleep::usleep(random_int(1_000, 20_000));
            goto beginning;
        }
        throw $exception;
    }

    fwrite(STDOUT, json_encode(['mode' => $mode, 'status' => $status], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, substr($exception::class.': '.$exception->getMessage(), 0, 2000)."\n");
    exit(1);
}
