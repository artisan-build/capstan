<?php

namespace App\Work;

use App\Models\WorkItem;
use App\Models\WorkMilestone;
use App\Models\WorkTask;

final readonly class WorkEventProjector
{
    public function __construct(private WorkOrchestrationService $engine) {}

    public function terminal(WorkItem $item): void
    {
        if ($item->state->isTerminal()) {
            $this->project($item, 'terminal:'.$item->id.':'.$item->state->value, 'child_'.$item->state->value);
            $this->engine->ended($item);
        }
    }

    public function blocked(WorkItem $item, WorkMilestone $milestone): void
    {
        if ($milestone->kind->value === 'blocked') {
            $this->project($item, 'blocked:'.$milestone->id, 'child_blocked');
        }
    }

    public function stalled(WorkItem $item): void
    {
        $this->project($item, 'stall:'.$item->id.':'.$item->fence, 'child_stalled');
    }

    public function expired(WorkItem $item): void
    {
        if ($item->attempts >= max(1, (int) config('capstan.work.orchestration.attempt_cap', 3))) {
            $this->project($item, 'attempt-cap:'.$item->id, 'child_attempt_cap');
        }
    }

    private function project(WorkItem $item, string $key, string $kind): void
    {
        $parentId = $item->workTask->parent_task_id;
        while ($parentId !== null) {
            $parent = WorkTask::query()->findOrFail($parentId);
            $this->engine->event($parent, $key, $kind, ['item_id' => $item->id, 'task_id' => $item->work_task_id, 'fence' => $item->fence]);
            $parentId = $parent->parent_task_id;
        }
    }
}
