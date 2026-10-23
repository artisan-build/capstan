<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string $work_item_id
 * @property string $public_token
 * @property string $storage_key
 * @property string $content_type
 * @property string $extension
 * @property int $size_bytes
 * @property CarbonImmutable $created_at
 * @property-read WorkTask $workTask
 * @property-read WorkItem $workItem
 *
 * @mixin IdeHelperWorkEvidence
 */
class WorkEvidence extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'work_task_id',
        'work_item_id',
        'public_token',
        'storage_key',
        'content_type',
        'extension',
        'size_bytes',
    ];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Work evidence is immutable.'));
        static::deleting(static fn () => throw new LogicException('Work evidence is permanent.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
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
