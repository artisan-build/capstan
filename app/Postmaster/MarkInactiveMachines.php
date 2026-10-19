<?php

namespace App\Postmaster;

use App\Models\Spoke;
use Carbon\CarbonImmutable;

final readonly class MarkInactiveMachines
{
    public function __invoke(CarbonImmutable $now): int
    {
        return Spoke::query()
            ->whereNull('retired_at')
            ->whereNull('orphaned_at')
            ->whereNull('inactive_at')
            ->whereNotNull('last_polled_at')
            ->where('last_polled_at', '<=', $now->subSeconds(MachineLifecycle::INACTIVE_AFTER_SECONDS))
            ->update([
                'inactive_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
