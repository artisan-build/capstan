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
 * @property int|null $claimant_spoke_id
 * @property string|null $claimant_actor
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $claimed_at
 * @property int $fence
 * @property int $attempts
 * @property CarbonImmutable|null $release_requested_at
 * @property CarbonImmutable|null $release_deadline
 * @property string|null $release_for_item_id
 * @property int|null $turn_cursor
 * @property int|null $turn_upper_bound
 * @property int|null $seat_fence
 * @property string|null $addressed_actor
 * @property string|null $fetched_brief_revision_id
 * @property string|null $last_progress
 * @property CarbonImmutable|null $stalled_at
 * @property CarbonImmutable|null $waiting_on_host_at
 * @property CarbonImmutable|null $waiting_on_host_notified_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkTask $workTask
 * @property-read Spoke|null $claimantSpoke
 * @property-read WorkBriefRevision|null $fetchedBriefRevision
 * @property-read Collection<int, WorkItemTransition> $transitions
 * @property-read Collection<int, WorkMilestone> $milestones
 *
 * @mixin IdeHelperWorkItem
 */
class WorkItem extends Model
{
    use HasUuids;

    // Claim result metadata, not a second persisted copy of the opaque seat reference.
    public ?string $session_ref = null;

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
        'claimant_spoke_id',
        'claimant_actor',
        'lease_until',
        'claimed_at',
        'fence',
        'attempts',
        'release_requested_at',
        'release_deadline',
        'release_for_item_id',
        'turn_cursor',
        'turn_upper_bound',
        'seat_fence',
        'addressed_actor',
        'fetched_brief_revision_id',
        'last_progress',
        'stalled_at',
        'waiting_on_host_at',
        'waiting_on_host_notified_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => WorkItemState::class,
            'claimant_spoke_id' => 'integer',
            'lease_until' => 'datetime',
            'claimed_at' => 'datetime',
            'fence' => 'integer',
            'attempts' => 'integer',
            'release_requested_at' => 'datetime',
            'release_deadline' => 'datetime',
            'turn_cursor' => 'integer',
            'turn_upper_bound' => 'integer',
            'seat_fence' => 'integer',
            'stalled_at' => 'datetime',
            'waiting_on_host_at' => 'datetime',
            'waiting_on_host_notified_at' => 'datetime',
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

    /** @return BelongsTo<Spoke, $this> */
    public function claimantSpoke(): BelongsTo
    {
        return $this->belongsTo(Spoke::class, 'claimant_spoke_id');
    }

    /** @return BelongsTo<WorkBriefRevision, $this> */
    public function fetchedBriefRevision(): BelongsTo
    {
        return $this->belongsTo(WorkBriefRevision::class, 'fetched_brief_revision_id');
    }

    /** @return HasMany<WorkItemTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkItemTransition::class)->oldest('occurred_at');
    }

    /** @return HasMany<WorkMilestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(WorkMilestone::class);
    }
}
