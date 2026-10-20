<?php

namespace App\Console\Commands;

use App\Enums\ProbeStatus;
use App\Models\Spoke;
use App\Models\SpokeProbe;
use App\Postmaster\ProbeFailureNotifier;
use App\Postmaster\ProbeManager;
use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SweepPostmasterProbes extends SystemAuthorityCommand
{
    protected $signature = 'postmaster:probe-sweep';

    protected $description = 'Fail overdue Postmaster spoke probes and send out-of-band notifications.';

    public function handle(ProbeManager $manager, ProbeFailureNotifier $notifier): int
    {
        $failed = 0;
        $now = now();

        SpokeProbe::query()
            ->where('status', ProbeStatus::Awaiting->value)
            ->where('expires_at', '<=', $now)
            ->select(['id', 'spoke_id'])
            ->chunkById(100, function ($candidates) use ($manager, $notifier, $now, &$failed): void {
                foreach ($candidates as $candidate) {
                    try {
                        $transitioned = DB::transaction(function () use ($candidate, $manager, $notifier, $now): bool {
                            // Match poll's spoke-then-probe lock order to avoid a PostgreSQL deadlock.
                            $spoke = Spoke::query()->whereKey($candidate->spoke_id)->lockForUpdate()->first();
                            $probe = SpokeProbe::query()->whereKey($candidate->id)->lockForUpdate()->first();

                            if (! $spoke instanceof Spoke
                                || ! $probe instanceof SpokeProbe
                                || $probe->status !== ProbeStatus::Awaiting
                                || $probe->expires_at->isAfter($now)) {
                                return false;
                            }

                            $newerPassed = SpokeProbe::query()
                                ->where('spoke_id', $spoke->id)
                                ->where('status', ProbeStatus::Passed->value)
                                ->where('issued_at', '>', $probe->issued_at)
                                ->exists();

                            if ($newerPassed) {
                                $manager->recordFailure($probe, $now, responded: false);

                                return true;
                            }

                            if ($manager->fail($spoke, $probe, $now, responded: false)) {
                                $notifier->notify($spoke, $probe);
                            }

                            return true;
                        });
                    } catch (Throwable $exception) {
                        Log::error('Postmaster probe failure notification failed.', [
                            'spoke_id' => $candidate->spoke_id,
                            'probe_id' => $candidate->id,
                            'exception' => $exception,
                        ]);

                        continue;
                    }

                    if ($transitioned) {
                        $failed++;
                    }
                }
            });

        $this->info("Failed {$failed} overdue probe(s).");

        return self::SUCCESS;
    }
}
