<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $parent_task_id
 * @property string|null $workflow_ref
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Team $team
 * @property-read Spoke|null $deviceSpoke
 * @property-read WorkTask|null $parent
 * @property-read Collection<int, WorkTask> $children
 * @property-read Collection<int, WorkBriefRevision> $briefRevisions
 * @property-read WorkBriefRevision|null $latestBriefRevision
 * @property-read Collection<int, WorkItem> $items
 * @property-read Collection<int, WorkMilestone> $milestones
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
            'cancelled_at' => 'datetime',
        ];
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

    /** @return HasMany<WorkBriefRevision, $this> */
    public function briefRevisions(): HasMany
    {
        return $this->hasMany(WorkBriefRevision::class)->orderBy('sequence');
    }

    /** @return HasOne<WorkBriefRevision, $this> */
    public function latestBriefRevision(): HasOne
    {
        return $this->hasOne(WorkBriefRevision::class)->ofMany('sequence', 'max');
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

    /** @return HasMany<WorkSteer, $this> */
    public function steers(): HasMany
    {
        return $this->hasMany(WorkSteer::class);
    }
}
