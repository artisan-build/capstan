<?php

namespace App\Models;

use App\Enums\WorkPriority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property int $team_id
 * @property string $title
 * @property string|null $user_actor_address
 * @property int|null $device_spoke_id
 * @property bool $failover
 * @property list<string> $required_capabilities
 * @property int $timebox_minutes
 * @property string $creator_actor
 * @property string $owner_actor
 * @property string|null $escalation_actor
 * @property WorkPriority $priority
 * @property int $dependency_version
 * @property bool $routing_pool_work
 * @property CarbonImmutable|null $expires_at
 * @property array{repo: string, number: int, url: string}|null $pr
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $parent_task_id
 * @property string|null $workflow_ref
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Team $team
 * @property-read Spoke|null $deviceSpoke
 * @property-read WorkTask|null $parent
 * @property-read Collection<int, WorkTask> $children
 * @property-read Collection<int, WorkTask> $dependencies
 * @property-read Collection<int, WorkTask> $dependents
 * @property-read WorkTaskExpiration|null $expiration
 * @property-read Collection<int, WorkBriefRevision> $briefRevisions
 * @property-read WorkBriefRevision|null $latestBriefRevision
 * @property-read Collection<int, WorkItem> $items
 * @property-read Collection<int, WorkMilestone> $milestones
 * @property-read Collection<int, WorkEvidence> $evidence
 * @property-read Collection<int, WorkSteer> $steers
 *
 * @mixin IdeHelperWorkTask
 */
class WorkTask extends Model
{
    use HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'failover' => true,
        'priority' => WorkPriority::P2->value,
    ];

    /** @var list<string> */
    protected $fillable = [
        'team_id',
        'title',
        'user_actor_address',
        'device_spoke_id',
        'failover',
        'required_capabilities',
        'timebox_minutes',
        'creator_actor',
        'owner_actor',
        'escalation_actor',
        'priority',
        'dependency_version',
        'routing_pool_work',
        'expires_at',
        'pr',
        'cancelled_at',
        'parent_task_id',
        'workflow_ref',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'device_spoke_id' => 'integer',
            'failover' => 'boolean',
            'required_capabilities' => 'array',
            'timebox_minutes' => 'integer',
            'priority' => WorkPriority::class,
            'dependency_version' => 'integer',
            'routing_pool_work' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'pr' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WorkTask $task): void {
            if (! $task->isDirty('routing_pool_work')) {
                $task->routing_pool_work = (bool) $task->team()->value('pool_work');
            }
            if ($task->parent_task_id === null) {
                $task->owner_actor ??= $task->creator_actor;
                $task->priority ??= WorkPriority::P2;

                return;
            }

            $root = self::query()->findOrFail($task->parent_task_id);
            while ($root->parent_task_id !== null) {
                $root = self::query()->findOrFail($root->parent_task_id);
            }
            $task->owner_actor = $root->owner_actor;
            $task->escalation_actor = $root->escalation_actor;
            $task->priority = $root->priority;
        });
    }

    public function effectiveEscalationActor(): string
    {
        return $this->escalation_actor ?? $this->owner_actor;
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Spoke, $this> */
    public function deviceSpoke(): BelongsTo
    {
        return $this->belongsTo(Spoke::class, 'device_spoke_id');
    }

    /** @return BelongsTo<WorkTask, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    /** @return HasMany<WorkTask, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    /** @return BelongsToMany<WorkTask, $this> */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'work_task_dependencies',
            'work_task_id',
            'depends_on_task_id',
        );
    }

    /** @return BelongsToMany<WorkTask, $this> */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'work_task_dependencies',
            'depends_on_task_id',
            'work_task_id',
        );
    }

    /** @return HasOne<WorkTaskExpiration, $this> */
    public function expiration(): HasOne
    {
        return $this->hasOne(WorkTaskExpiration::class);
    }

    /** @return HasMany<WorkBriefRevision, $this> */
    public function briefRevisions(): HasMany
    {
        return $this->hasMany(WorkBriefRevision::class)->orderBy('sequence');
    }

    /** @return HasOne<WorkBriefRevision, $this> */
    public function latestBriefRevision(): HasOne
    {
        return $this->hasOne(WorkBriefRevision::class)->orderByDesc('sequence');
    }

    /** @return HasMany<WorkItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    /** @return HasManyThrough<WorkMilestone, WorkItem, $this> */
    public function milestones(): HasManyThrough
    {
        return $this->hasManyThrough(WorkMilestone::class, WorkItem::class);
    }

    /** @return HasMany<WorkEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(WorkEvidence::class);
    }

    /** @return HasMany<WorkSteer, $this> */
    public function steers(): HasMany
    {
        return $this->hasMany(WorkSteer::class);
    }
}
