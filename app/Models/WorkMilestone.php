<?php

namespace App\Models;

use App\Enums\WorkMilestoneKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string|null $work_item_id
 * @property WorkMilestoneKind $kind
 * @property array<string, mixed> $payload
 * @property string $author_actor
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkTask $workTask
 * @property-read WorkItem|null $workItem
 */
class WorkMilestone extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'work_task_id',
        'work_item_id',
        'kind',
        'payload',
        'author_actor',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => WorkMilestoneKind::class,
            'payload' => 'array',
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
