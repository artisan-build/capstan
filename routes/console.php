<?php

use App\Work\WorkOrchestrationService;
use App\Work\WorkSeatService;
use App\Work\WorkSweepService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('postmaster:probe-sweep')->everyMinute()->withoutOverlapping(5);
Schedule::command('postmaster:retire-dead-spokes --local')->everyMinute()->withoutOverlapping(5);

Artisan::command('work:liveness-sweep', function (WorkSweepService $sweeps): void {
    $sweeps->liveness(CarbonImmutable::now());
});
Artisan::command('work:failover-sweep', function (WorkSweepService $sweeps): void {
    $sweeps->failover(CarbonImmutable::now());
});
Schedule::command('work:liveness-sweep')->everyMinute()->withoutOverlapping(5);
Schedule::command('work:failover-sweep')->everyMinute()->withoutOverlapping(5);

Artisan::command('work:orchestration-sweep', function (WorkOrchestrationService $engine, WorkSeatService $seats): void {
    $now = CarbonImmutable::now();
    $engine->dueWakes($now);
    $seats->sweep($now);
});
Schedule::command('work:orchestration-sweep')->everyMinute()->withoutOverlapping(5);
