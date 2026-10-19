<?php

namespace App\Postmaster;

use App\Enums\MachineState;
use App\Enums\SpokeLiveness;
use App\Models\Spoke;
use Carbon\CarbonImmutable;

final readonly class MachineLifecycle
{
    public const int INACTIVE_AFTER_SECONDS = 300;

    public function state(Spoke $spoke, CarbonImmutable $now): MachineState
    {
        if ($spoke->retired_at !== null) {
            return MachineState::Retired;
        }

        if ($spoke->orphaned_at !== null) {
            return MachineState::Orphaned;
        }

        if ($spoke->probe_status === SpokeLiveness::Red) {
            return MachineState::Unhealthy;
        }

        if ($spoke->inactive_at !== null
            || ($spoke->last_polled_at !== null
                && $spoke->last_polled_at->addSeconds(self::INACTIVE_AFTER_SECONDS)->lessThanOrEqualTo($now))) {
            return MachineState::Inactive;
        }

        if ($spoke->probe_status === SpokeLiveness::Green) {
            return MachineState::Active;
        }

        return MachineState::Pending;
    }
}
