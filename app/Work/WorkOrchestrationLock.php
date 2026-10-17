<?php

namespace App\Work;

use App\Features\Work;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

final class WorkOrchestrationLock
{
    /**
     * All source writers lock ancestor tasks root-first, before any item/seat.
     * A conditional no-op write also acquires SQLite's writer reservation.
     *
     * @return list<WorkTask>
     */
    public function tasks(string $taskId): array
    {
        if (! Feature::active(Work::class)) {
            return [];
        }
        if (DB::getDriverName() === 'sqlite') {
            // Reserve the single writer before reading ancestry. Upgrading an old
            // WAL read snapshot cannot wait on busy_timeout and fails immediately.
            DB::table('work_tasks')->where('id', $taskId)->update(['updated_at' => DB::raw('updated_at')]);
        }
        $tasks = [];
        $seen = [];
        while (! isset($seen[$taskId])) {
            $seen[$taskId] = true;
            $task = WorkTask::query()->findOrFail($taskId);
            $tasks[] = $task;
            if ($task->parent_task_id === null) {
                break;
            }
            $taskId = $task->parent_task_id;
        }
        $tasks = array_reverse($tasks);
        foreach ($tasks as $task) {
            WorkTask::query()->whereKey($task->id)->update(['updated_at' => DB::raw('updated_at')]);
            $task->refresh();
        }

        return $tasks;
    }

    /** Caller holds the task lock in a transaction. */
    public function seat(WorkTask $task): WorkOrchestratorSeat
    {
        return WorkOrchestratorSeat::query()->firstOrCreate(['work_task_id' => $task->id], ['failover' => $task->failover])->refresh();
    }
}
