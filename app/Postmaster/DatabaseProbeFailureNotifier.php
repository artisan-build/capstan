<?php

namespace App\Postmaster;

use App\Machine\MachineConditionNotifier;
use App\Models\Spoke;
use App\Models\SpokeProbe;

final readonly class DatabaseProbeFailureNotifier implements ProbeFailureNotifier
{
    public function __construct(private MachineConditionNotifier $conditions) {}

    public function notify(Spoke $spoke, SpokeProbe $probe): void
    {
        $this->conditions->open(
            $spoke,
            MachineConditionNotifier::PROBE_FAILURE,
            $spoke->probe_failed_at?->toImmutable() ?? now()->toImmutable(),
        );
    }
}
