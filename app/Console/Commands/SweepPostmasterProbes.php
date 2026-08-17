<?php

namespace App\Console\Commands;

use App\Enums\ProbeStatus;
use App\Features\Postmaster;
use App\Models\Spoke;
use App\Models\SpokeProbe;
use App\Postmaster\ProbeFailureNotifier;
use App\Postmaster\ProbeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Throwable;

class SweepPostmasterProbes extends Command
{
    protected $signature = 'postmaster:probe-sweep';

    protected $description = 'Fail overdue Postmaster spoke probes and send out-of-band notifications.';

    public function handle(ProbeManager $manager, ProbeFailureNotifier $notifier): int
    {
        if (! Feature::active(Postmaster::class)) {
            $voided = SpokeProbe::query()
                ->whereIn('status', [ProbeStatus::Issued->value, ProbeStatus::Awaiting->value])
                ->delete();
            $this->info("Postmaster disabled; voided {$voided} outstanding probe(s).");

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

                        $newerPassed = SpokeProbe::query()
                            ->where('spoke_id', $spoke->id)
                            ->where('status', ProbeStatus::Passed->value)
                            ->where('issued_at', '>', $probe->issued_at)
                            ->exists();

                        if ($newerPassed) {
                            $manager->recordFailure($probe, $now, responded: false);

                            return [$spoke, $probe, false];
                        }

                        $notify = $manager->fail($spoke, $probe, $now, responded: false);

                        return [$spoke, $probe, $notify];
                    });

                    if ($transition !== null) {
                        [$spoke, $probe, $notify] = $transition;

                        if ($notify) {
                            try {
                                $notifier->notify($spoke, $probe);
                            } catch (Throwable $exception) {
                                Log::error('Postmaster probe failure notification failed.', [
                                    'spoke_id' => $spoke->id,
                                    'probe_id' => $probe->probe_id,
                                    'exception' => $exception,
                                ]);
                            }
                        }

                        $failed++;
                    }
                }
            });

        $this->info("Failed {$failed} overdue probe(s).");

        return self::SUCCESS;
    }
}
