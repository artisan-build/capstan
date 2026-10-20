<?php

namespace App\Models;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string $triggering_event_id
 * @property string $decision
 * @property string $rationale
 * @property array<string, mixed> $evidence
 * @mixin IdeHelperWorkOrchestrationDecision
 */
class WorkOrchestrationDecision extends WorkAppendOnlyRecord
{
    /** @var list<string> */
    protected $fillable = ['work_task_id', 'triggering_event_id', 'decision', 'rationale', 'evidence'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }
}
