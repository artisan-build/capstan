<?php

namespace App\Postmaster;

use App\Machine\MachineConditionNotifier;
use App\Models\Spoke;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class MarkInactiveMachines
{
    public function __construct(private MachineConditionNotifier $conditions) {}

    public function __invoke(CarbonImmutable $now): int
    {
        $count = 0;
        $cutoff = $now->subSeconds(MachineLifecycle::INACTIVE_AFTER_SECONDS);
        Spoke::query()
            ->where('credential_backed', true)
            ->whereNull('retired_at')
            ->whereNull('orphaned_at')
            ->whereNull('inactive_at')
            ->whereNotNull('last_polled_at')
            ->where('last_polled_at', '<=', $cutoff)
            ->select('id')
            ->chunkById(100, function ($candidates) use ($now, $cutoff, &$count): void {
                foreach ($candidates as $candidate) {
                    $changed = DB::transaction(function () use ($candidate, $now, $cutoff): bool {
                        $machine = Spoke::query()
                            ->whereKey($candidate->id)
                            ->where('credential_backed', true)
                            ->whereNull('retired_at')
                            ->whereNull('orphaned_at')
                            ->whereNull('inactive_at')
                            ->whereNotNull('last_polled_at')
                            ->where('last_polled_at', '<=', $cutoff)
                            ->lockForUpdate()
                            ->first();
                        if (! $machine instanceof Spoke) {
                            return false;
                        }

                        $machine->forceFill(['inactive_at' => $now, 'updated_at' => $now])->save();
                        $this->conditions->open($machine, MachineConditionNotifier::INACTIVE, $now);

                        return true;
                    });
                    $count += $changed ? 1 : 0;
                }
            });

        return $count;
    }
}
