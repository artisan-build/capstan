<?php

namespace App\Console\Commands;

use App\Enums\ProbeStatus;
use App\Models\Spoke;
use App\Models\SpokeProbe;
use App\Postmaster\ProbeFailureNotifier;
use App\Postmaster\ProbeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SweepPostmasterProbes extends Command
{
    protected $signature = 'postmaster:probe-sweep';

    protected $description = 'Fail overdue Postmaster spoke probes and send out-of-band notifications.';

    public function handle(ProbeManager $manager, ProbeFailureNotifier $notifier): int
    {
        if (! config('capstan.features.postmaster')) {
            $this->info('Failed 0 overdue probe(s).');

            return self::SUCCESS;
        }

        $failed = 0;
        $now = now();

        SpokeProbe::query()
            ->where('status', ProbeStatus::Awaiting->value)
            ->where('expires_at', '<=', $now)
            ->select(['id', 'spoke_id'])
            ->chunkById(100, function ($candidates) use ($manager, $notifier, $now, &$failed): void {
                foreach ($candidates as $candidate) {
                    $transition = DB::transaction(function () use ($candidate, $manager, $now): ?array {
                        // Match poll's spoke-then-probe lock order to avoid a PostgreSQL deadlock.
                        $spoke = Spoke::query()->whereKey($candidate->spoke_id)->lockForUpdate()->first();
                        $probe = SpokeProbe::query()->whereKey($candidate->id)->lockForUpdate()->first();

                        if (! $spoke instanceof Spoke
                            || ! $probe instanceof SpokeProbe
                            || $probe->status !== ProbeStatus::Awaiting
                            || $probe->expires_at->isAfter($now)) {
                            return null;
                        }

                        $manager->fail($spoke, $probe, $now, responded: false);

                        return [$spoke, $probe];
                    });

                    if ($transition !== null) {
                        [$spoke, $probe] = $transition;
                        $notifier->notify($spoke, $probe);
                        $failed++;
                    }
                }
            });

        $this->info("Failed {$failed} overdue probe(s).");

        return self::SUCCESS;
    }
}
