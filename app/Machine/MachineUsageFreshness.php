<?php

namespace App\Machine;

use App\Models\MachineUsageProvider;
use App\Models\MachineUsageReport;
use Carbon\CarbonImmutable;

final class MachineUsageFreshness
{
    public function providerIsFresh(
        MachineUsageReport $report,
        MachineUsageProvider $provider,
        CarbonImmutable $now,
    ): bool {
        return $report->last_success_received_at !== null
            && $report->last_success_received_at->greaterThanOrEqualTo($now->subMinutes(10))
            && $provider->fetched_at->greaterThanOrEqualTo($now->subMinutes(15))
            && $provider->fetched_at->lessThanOrEqualTo($now->addMinutes(5))
            && $provider->source_stale !== true
            && ($provider->expires_at === null || $provider->expires_at->isAfter($now));
    }
}
