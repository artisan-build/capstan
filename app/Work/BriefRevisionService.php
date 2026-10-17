<?php

namespace App\Work;

use App\Models\WorkBriefRevision;
use App\Models\WorkTask;
use Illuminate\Support\Facades\DB;

class BriefRevisionService
{
    public function append(WorkTask $task, string $content, string $authorActor): WorkBriefRevision
    {
        return DB::transaction(function () use ($task, $content, $authorActor): WorkBriefRevision {
            $lockedTask = WorkTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            $lastSequence = (int) $lockedTask->briefRevisions()->max('sequence');

            return $lockedTask->briefRevisions()->create([
                'sequence' => $lastSequence + 1,
                'content' => $content,
                'author_actor' => $authorActor,
            ]);
        });
    }
}
