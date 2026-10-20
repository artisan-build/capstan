<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string $work_item_id
 * @property string $request_key
 * @property string $addressed_actor
 * @property string $question
 * @property array<string, mixed> $metadata
 * @property string|null $answer
 * @property string|null $answered_by
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $notified_at
 * @mixin IdeHelperWorkEscalation
 */
class WorkEscalation extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['work_task_id', 'work_item_id', 'request_key', 'addressed_actor', 'question', 'metadata', 'answer', 'answered_by', 'answered_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'answered_at' => 'datetime', 'notified_at' => 'datetime'];
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
