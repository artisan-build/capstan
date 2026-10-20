<?php

namespace App\Models;

/**
 * @property string $id
 * @property string $work_task_id
 * @property int $sequence
 * @property string $source_key
 * @property string $kind
 * @property array<string, mixed> $evidence
 * @property string|null $author_task_id
 * @property bool $wakes
 * @mixin IdeHelperWorkOrchestrationEvent
 */
class WorkOrchestrationEvent extends WorkAppendOnlyRecord
{
    /** @var list<string> */
    protected $fillable = ['work_task_id', 'sequence', 'source_key', 'kind', 'evidence', 'author_task_id', 'wakes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'evidence' => 'array', 'wakes' => 'boolean'];
    }
}
