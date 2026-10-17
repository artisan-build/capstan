<?php

namespace App\Models;

use App\Enums\WorkMilestoneKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $work_item_id
 * @property int $fence
 * @property WorkMilestoneKind $kind
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 * @property-read WorkItem $workItem
 *
 * @mixin IdeHelperWorkMilestone
 */
class WorkMilestone extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'work_item_id',
        'fence',
        'kind',
        'payload',
    ];

    protected static function booted(): void
    {
        static::creating(function (WorkMilestone $milestone): void {
            if ($milestone->getAttribute('work_item_id') === null || $milestone->getAttribute('fence') === null) {
                throw new LogicException('Work milestones require an item and fence.');
            }
        });
        static::updating(static fn () => throw new LogicException('Work milestones are append-only.'));
        static::deleting(static fn () => throw new LogicException('Work milestones are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => WorkMilestoneKind::class,
            'payload' => 'array',
            'fence' => 'integer',
        ];
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }
}
