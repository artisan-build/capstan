<?php

namespace App\Models;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string $work_item_id
 * @property int $sequence
 * @property int $through_cursor
 * @property string $content
 *
 * @mixin IdeHelperWorkStateRevision
 */
class WorkStateRevision extends WorkAppendOnlyRecord
{
    /** @var list<string> */
    protected $fillable = ['work_task_id', 'work_item_id', 'sequence', 'through_cursor', 'content'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'through_cursor' => 'integer'];
    }
}
