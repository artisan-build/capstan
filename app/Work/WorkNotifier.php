<?php

namespace App\Work;

use App\Models\Spoke;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;

interface WorkNotifier
{
    public function stalled(WorkItem $item): void;

    public function missedHeartbeat(Spoke $spoke): void;

    public function waitingOnHost(WorkItem $item): void;

    public function seatWaiting(WorkOrchestratorSeat $seat): void;
}
