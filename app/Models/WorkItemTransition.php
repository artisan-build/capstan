<?php

namespace App\Models;

use App\Enums\WorkItemState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $work_item_id
 * @property WorkItemState $from_state
 * @property WorkItemState $to_state
 * @property string $actor_kind
 * @property string $actor_id
 * @property int $fence
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkItem $workItem
 *
 * @mixin IdeHelperWorkItemTransition
 */
class WorkItemTransition extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'work_item_id',
        'from_state',
        'to_state',
        'actor_kind',
        'actor_id',
        'fence',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Work item transitions are append-only.'));
        static::deleting(static fn () => throw new LogicException('Work item transitions are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_state' => WorkItemState::class,
            'to_state' => WorkItemState::class,
            'fence' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }
}
