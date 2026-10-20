<?php

namespace App\Postmaster;

use App\Machine\MachineConditionNotifier;
use App\Models\Spoke;
use Carbon\CarbonImmutable;

final readonly class MarkInactiveMachines
{
    public function __construct(private MachineConditionNotifier $conditions) {}

    public function __invoke(CarbonImmutable $now): int
    {
        $count = 0;
        Spoke::query()
            ->whereNull('retired_at')
            ->whereNull('orphaned_at')
            ->whereNull('inactive_at')
            ->whereNotNull('last_polled_at')
            ->where('last_polled_at', '<=', $now->subSeconds(MachineLifecycle::INACTIVE_AFTER_SECONDS))
            ->select('id')
            ->chunkById(100, function ($candidates) use ($now, &$count): void {
                foreach ($candidates as $candidate) {
                    $machine = Spoke::query()->whereKey($candidate->id)->whereNull('inactive_at')->first();
                    if (! $machine instanceof Spoke) {
                        continue;
                    }
                    $machine->forceFill(['inactive_at' => $now, 'updated_at' => $now])->save();
                    $this->conditions->open($machine, MachineConditionNotifier::INACTIVE, $now);
                    $count++;
                }
            });

        return $count;
    }
}
