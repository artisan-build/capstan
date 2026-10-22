<?php

namespace App\Work;

use App\Models\Spoke;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use App\Notifications\WorkNotification;
use ArtisanBuild\BuiltForCloud\User;

final readonly class DatabaseWorkNotifier implements WorkNotifier
{
    public function __construct(private LogWorkNotifier $logger) {}

    public function escalated(WorkEscalation $escalation): void
    {
        $this->logger->escalated($escalation);
        $this->notify($escalation->addressed_actor, [
            'kind' => 'escalation_opened',
            'task_id' => $escalation->work_task_id,
            'item_id' => $escalation->work_item_id,
            'escalation_id' => $escalation->id,
            'state' => 'open',
        ]);
    }

    public function reassigned(WorkTask $task, string $oldOwner, string $newOwner, string $mode): void
    {
        $this->logger->reassigned($task, $oldOwner, $newOwner, $mode);
        $this->notify($task->effectiveEscalationActor(), [
            'kind' => 'task_reassigned',
            'task_id' => $task->id,
            'old_owner' => $oldOwner,
            'new_owner' => $newOwner,
            'escalation_actor' => $task->effectiveEscalationActor(),
            'mode' => $mode,
        ]);
    }

    public function stalled(WorkItem $item): void
    {
        $this->logger->stalled($item);
        $actor = $this->taskEscalationActor($item->work_task_id);
        if ($actor !== null) {
            $this->notify($actor, [
                'kind' => 'item_stalled',
                'task_id' => $item->work_task_id,
                'item_id' => $item->id,
                'fence' => $item->fence,
                'state' => 'stalled',
            ]);
        }
    }

    public function missedHeartbeat(Spoke $spoke): void
    {
        $this->logger->missedHeartbeat($spoke);
        $this->notify($spoke->actor_id, [
            'kind' => 'host_missed_heartbeat',
            'spoke_id' => $spoke->id,
            'state' => 'missed',
        ]);
    }

    public function waitingOnHost(WorkItem $item): void
    {
        $this->logger->waitingOnHost($item);
        $actor = $this->taskEscalationActor($item->work_task_id);
        if ($actor !== null) {
            $this->notify($actor, [
                'kind' => 'item_waiting_on_host',
                'task_id' => $item->work_task_id,
                'item_id' => $item->id,
                'state' => 'waiting',
            ]);
        }
    }

    public function seatWaiting(WorkOrchestratorSeat $seat): void
    {
        $this->logger->seatWaiting($seat);
        $actor = $this->taskEscalationActor($seat->work_task_id);
        if ($actor !== null) {
            $this->notify($actor, [
                'kind' => 'seat_waiting',
                'task_id' => $seat->work_task_id,
                'seat_id' => $seat->id,
                'state' => 'waiting',
            ]);
        }
    }

    private function taskEscalationActor(string $taskId): ?string
    {
        return WorkTask::query()->find($taskId)?->effectiveEscalationActor();
    }

    /** @param array<string, int|string> $data */
    private function notify(string $actorId, array $data): void
    {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $actorId) !== 1) {
            return;
        }
        $user = User::query()->find($actorId);
        if ($user instanceof User) {
            $user->notify(new WorkNotification($data));
        }
    }
}
