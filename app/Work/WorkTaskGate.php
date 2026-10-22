<?php

namespace App\Work;

use App\Enums\WorkExpirationProvenance;
use App\Enums\WorkItemState;
use App\Models\WorkTask;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class WorkTaskGate
{
    /** @return array{at: CarbonImmutable, provenance: WorkExpirationProvenance}|null */
    public function effectiveExpiry(WorkTask $task): ?array
    {
        $effective = null;
        $cursor = $task;
        $seen = [];

        while (true) {
            if (isset($seen[$cursor->id])) {
                throw new DomainException('Work task ancestry contains a cycle.');
            }
            $seen[$cursor->id] = true;

            if ($cursor->expires_at !== null && ($effective === null || $cursor->expires_at->lt($effective['at']))) {
                $effective = [
                    'at' => $cursor->expires_at,
                    'provenance' => $cursor->id === $task->id
                        ? WorkExpirationProvenance::Own
                        : WorkExpirationProvenance::Ancestor,
                ];
            }

            if ($cursor->parent_task_id === null) {
                return $effective;
            }

            $cursor = WorkTask::query()->findOrFail($cursor->parent_task_id);
        }
    }

    public function dependenciesSuccessful(WorkTask $task): bool
    {
        foreach ($task->dependencies()->get() as $dependency) {
            if (! $this->successful($dependency)) {
                return false;
            }
        }

        return true;
    }

    public function successful(WorkTask $task): bool
    {
        return $task->cancelled_at === null
            && $task->items()->exists()
            && ! $task->items()->where('state', '!=', WorkItemState::Acted->value)->exists();
    }

    public function claimable(WorkTask $task, CarbonImmutable $now): bool
    {
        $expiry = $this->effectiveExpiry($task);

        return ($expiry === null || $expiry['at']->gt($now))
            && $this->dependenciesSuccessful($task);
    }

    /** @return array<string, int> */
    public function lockDependencies(WorkTask $task): array
    {
        $dependencies = $task->dependencies()->orderBy('work_tasks.id')->get();
        foreach ($dependencies as $dependency) {
            WorkTask::query()->whereKey($dependency->id)
                ->update(['updated_at' => DB::raw('updated_at')]);
            $dependency->refresh();
        }

        return $dependencies->mapWithKeys(
            fn (WorkTask $dependency): array => [$dependency->id => $dependency->dependency_version],
        )->all();
    }

    public function root(WorkTask $task): WorkTask
    {
        $cursor = $task;
        $seen = [];

        while ($cursor->parent_task_id !== null) {
            if (isset($seen[$cursor->id])) {
                throw new DomainException('Work task ancestry contains a cycle.');
            }
            $seen[$cursor->id] = true;
            $cursor = WorkTask::query()->findOrFail($cursor->parent_task_id);
        }

        return $cursor;
    }
}
