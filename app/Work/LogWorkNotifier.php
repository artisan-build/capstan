<?php

namespace App\Work;

use App\Models\Spoke;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use Illuminate\Support\Facades\Log;

final class LogWorkNotifier implements WorkNotifier
{
    public function escalated(WorkEscalation $escalation): void
    {
        Log::warning('Work escalation requires an answer.', ['task_id' => $escalation->work_task_id, 'item_id' => $escalation->work_item_id, 'escalation_id' => $escalation->id]);
    }

    public function reassigned(WorkTask $task, string $oldOwner, string $newOwner, string $mode): void
    {
        Log::warning('Work task reassigned.', [
            'task_id' => $task->id,
            'old_owner' => $oldOwner,
            'new_owner' => $newOwner,
            'mode' => $mode,
        ]);
    }

    public function seatWaiting(WorkOrchestratorSeat $seat): void
    {
        Log::warning('Work orchestrator waiting on pinned host.', ['task_id' => $seat->work_task_id, 'seat_id' => $seat->id]);
    }

    public function stalled(WorkItem $item): void
    {
        Log::warning('Work item stalled.', ['item_id' => $item->id, 'fence' => $item->fence]);
    }

    public function preempted(WorkItem $item): void
    {
        Log::warning('Work item preempted.', [
            'task_id' => $item->work_task_id,
            'item_id' => $item->id,
            'fence' => $item->fence,
            'escalation_actor' => $item->workTask->effectiveEscalationActor(),
        ]);
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
