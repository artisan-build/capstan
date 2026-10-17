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
 * @property int $sequence
 * @property string $content
 * @property string $author_actor
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkTask $workTask
 *
 * @mixin IdeHelperWorkBriefRevision
 */
class WorkBriefRevision extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'work_task_id',
        'sequence',
        'content',
        'author_actor',
    ];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Work brief revisions are append-only.'));
        static::deleting(static fn () => throw new LogicException('Work brief revisions are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<WorkTask, $this> */
    public function workTask(): BelongsTo
    {
        return $this->belongsTo(WorkTask::class);
    }
}
