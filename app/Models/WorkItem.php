<?php

namespace App\Models;

use App\Enums\WorkItemState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string $role
 * @property WorkItemState $state
 * @property string|null $claimant_spoke_address
 * @property string|null $claimant_actor
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $claimed_at
 * @property int $fence
 * @property int $attempts
 * @property int|null $fetched_brief_revision
 * @property string|null $last_progress
 * @property CarbonImmutable|null $stalled_at
 * @property CarbonImmutable|null $host_wait_started_at
 * @property CarbonImmutable|null $host_wait_until
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkTask $workTask
 * @property-read Collection<int, WorkItemTransition> $transitions
 */
class WorkItem extends Model
{
    use HasUuids;

    public const string ROLE_ORCHESTRATOR = 'orchestrator';

    public const string ROLE_HUMAN = 'human';

    public const int LAST_PROGRESS_MAX_LENGTH = 2000;

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => WorkItemState::Queued->value,
        'fence' => 0,
        'attempts' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'work_task_id',
        'role',
        'state',
        'claimant_spoke_address',
        'claimant_actor',
        'lease_until',
        'claimed_at',
        'fence',
        'attempts',
        'fetched_brief_revision',
        'last_progress',
        'stalled_at',
        'host_wait_started_at',
        'host_wait_until',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => WorkItemState::class,
            'lease_until' => 'datetime',
            'claimed_at' => 'datetime',
            'fence' => 'integer',
            'attempts' => 'integer',
            'fetched_brief_revision' => 'integer',
            'stalled_at' => 'datetime',
            'host_wait_started_at' => 'datetime',
            'host_wait_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (WorkItem $item): void {
            if ($item->last_progress !== null && mb_strlen($item->last_progress) > self::LAST_PROGRESS_MAX_LENGTH) {
                throw new InvalidArgumentException('Work item progress exceeds its maximum length.');
            }
        });
    }

    /** @return BelongsTo<WorkTask, $this> */
    public function workTask(): BelongsTo
    {
        return $this->belongsTo(WorkTask::class);
    }

    /** @return HasMany<WorkItemTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkItemTransition::class)->orderBy('occurred_at');
    }
}
