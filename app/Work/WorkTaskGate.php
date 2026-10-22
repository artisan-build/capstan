<?php

namespace App\Work;

use App\Enums\WorkExpirationReason;
use App\Enums\WorkItemState;
use App\Models\WorkTask;
use Carbon\CarbonImmutable;
use DomainException;

final class WorkTaskGate
{
    /** @return array{at: CarbonImmutable, reason: WorkExpirationReason}|null */
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
                    'reason' => $cursor->id === $task->id
                        ? WorkExpirationReason::OwnExpiry
                        : WorkExpirationReason::AncestorExpiry,
                ];
            }

            if ($cursor->parent_task_id === null) {
                return $effective;
            }

            $cursor = WorkTask::query()->findOrFail($cursor->parent_task_id);
        }
    }

    public function dependenciesSuccessful(WorkTask $task, ?CarbonImmutable $now = null): bool
    {
        foreach ($task->dependencies()->get() as $dependency) {
            if (! $this->successful($dependency, $now)) {
                return false;
            }
        }

        return true;
    }

    public function successful(WorkTask $task, ?CarbonImmutable $now = null): bool
    {
        $expiry = $this->effectiveExpiry($task);
        $now ??= CarbonImmutable::now();

        return $task->cancelled_at === null
            && ($expiry === null || $expiry['at']->gt($now))
            && $task->items()->exists()
            && ! $task->items()->where('state', '!=', WorkItemState::Acted->value)->exists();
    }

    public function claimable(WorkTask $task, CarbonImmutable $now): bool
    {
        $expiry = $this->effectiveExpiry($task);

        return ($expiry === null || $expiry['at']->gt($now))
            && $this->dependenciesSuccessful($task, $now);
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
