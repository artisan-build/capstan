<?php

namespace App\Models;

use App\Enums\WorkExpirationReason;
use App\Enums\WorkPriority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string $owner_actor
 * @property WorkPriority $priority
 * @property int $waited_seconds
 * @property WorkExpirationReason $reason
 * @property CarbonImmutable $expired_at
 * @property CarbonImmutable $created_at
 * @property-read WorkTask $workTask
 */
class WorkTaskExpiration extends WorkAppendOnlyRecord
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'work_task_id',
        'owner_actor',
        'priority',
        'waited_seconds',
        'reason',
        'expired_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => WorkPriority::class,
            'waited_seconds' => 'integer',
            'reason' => WorkExpirationReason::class,
            'expired_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkTask, $this> */
    public function workTask(): BelongsTo
    {
        return $this->belongsTo(WorkTask::class);
    }
}
