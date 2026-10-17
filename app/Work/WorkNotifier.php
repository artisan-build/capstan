<?php

namespace App\Work;

use App\Models\Spoke;
use App\Models\WorkItem;

interface WorkNotifier
{
    public function stalled(WorkItem $item): void;

    public function missedHeartbeat(Spoke $spoke): void;

    public function waitingOnHost(WorkItem $item): void;
}
