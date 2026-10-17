<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string|null $work_item_id
 * @property string $text
 * @property string $author_actor
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkTask $workTask
 * @property-read WorkItem|null $workItem
 *
 * @mixin IdeHelperWorkSteer
 */
class WorkSteer extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'work_task_id',
        'work_item_id',
        'text',
        'author_actor',
        'read_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkTask, $this> */
    public function workTask(): BelongsTo
    {
        return $this->belongsTo(WorkTask::class);
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }
}
