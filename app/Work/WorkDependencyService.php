<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Http\ApiErrorException;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use Illuminate\Support\Facades\DB;

final readonly class WorkDependencyService
{
    public function __construct(
        private WorkTaskGate $gate,
        private WorkOrchestrationService $orchestration,
    ) {}

    /** @param list<string> $dependencyIds */
    public function replace(WorkTask $task, array $dependencyIds): void
    {
        DB::transaction(function () use ($task, $dependencyIds): void {
            $ids = array_values(array_unique($dependencyIds));
            sort($ids);

            if (count($ids) !== count($dependencyIds) || in_array($task->id, $ids, true)) {
                throw $this->invalid();
            }

            $dependencies = WorkTask::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($dependencies->count() !== count($ids)) {
                throw $this->invalid();
            }

            foreach ($dependencies as $dependency) {
                if ($dependency->team_id !== $task->team_id
                    || $this->isDescendantOf($dependency, $task)
                    || $this->reaches($dependency, $task->id)) {
                    throw $this->invalid();
                }
            }

            $task->dependencies()->sync($ids);
            $task->increment('dependency_version');
        }, 5);
    }

    public function signalFailed(WorkTask $dependency): void
    {
        if ($this->gate->successful($dependency)) {
            return;
        }
        if ($dependency->cancelled_at === null && ! $dependency->expiration()->exists() && ! $dependency->items()
            ->whereIn('state', [WorkItemState::Failed->value, WorkItemState::Cancelled->value, WorkItemState::Expired->value])
            ->exists()) {
            return;
        }

        foreach ($dependency->dependents()->get() as $dependent) {
            $root = $this->gate->root($dependent);
            $key = 'dependency-failed:'.$dependent->id.':'.$dependency->id;
            $evidence = ['task_id' => $dependent->id, 'dependency_task_id' => $dependency->id];

            if (WorkOrchestratorSeat::query()->where('work_task_id', $root->id)->whereNull('stopped_at')->exists()) {
                $this->orchestration->event($root, $key, 'dependency_failed', $evidence, inheritAuthor: false);

                continue;
            }

            $this->orchestration->signalOwner(
                $root,
                $key,
                'A Work dependency failed or was cancelled.',
                [...$evidence, 'reason' => 'dependency_failed'],
            );
        }
    }

    private function isDescendantOf(WorkTask $candidate, WorkTask $task): bool
    {
        $cursor = $candidate;
        $seen = [];

        while ($cursor->parent_task_id !== null) {
            if (isset($seen[$cursor->id])) {
                return true;
            }
            $seen[$cursor->id] = true;
            if ($cursor->parent_task_id === $task->id) {
                return true;
            }
            $cursor = WorkTask::query()->findOrFail($cursor->parent_task_id);
        }

        return false;
    }

    /** @param array<string, true> $seen */
    private function reaches(WorkTask $from, string $targetId, array $seen = []): bool
    {
        if ($from->id === $targetId) {
            return true;
        }
        if (isset($seen[$from->id])) {
            return false;
        }
        $seen[$from->id] = true;

        foreach ($from->dependencies()->get() as $dependency) {
            if ($this->reaches($dependency, $targetId, $seen)) {
                return true;
            }
        }

        return false;
    }

    private function invalid(): ApiErrorException
    {
        return new ApiErrorException(422, 'invalid_dependencies', 'Work dependencies are invalid.');
    }
}
