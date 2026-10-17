<?php

namespace App\Work;

use App\Models\Spoke;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use Illuminate\Support\Facades\Log;

final class LogWorkNotifier implements WorkNotifier
{
    public function seatWaiting(WorkOrchestratorSeat $seat): void
    {
        Log::warning('Work orchestrator waiting on pinned host.', ['task_id' => $seat->work_task_id, 'seat_id' => $seat->id]);
    }

    public function stalled(WorkItem $item): void
    {
        Log::warning('Work item stalled.', ['item_id' => $item->id, 'fence' => $item->fence]);
    }

    public function missedHeartbeat(Spoke $spoke): void
    {
        Log::warning('Work host missed heartbeat.', ['spoke_id' => $spoke->id]);
    }

    public function waitingOnHost(WorkItem $item): void
    {
        Log::warning('Work item waiting on pinned host.', ['item_id' => $item->id]);
    }
}
