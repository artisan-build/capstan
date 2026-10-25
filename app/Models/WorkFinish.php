<?php

namespace App\Models;

use App\Casts\WorkPullRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $work_item_id
 * @property int $fence
 * @property string $writeup
 * @property array{repo: string, number: int, url: string} $pr
 * @property string $status
 * @property string|null $orchestration_event_id
 * @property string|null $verdict_actor
 * @property int|null $verdict_spoke_id
 * @property int|null $verdict_seat_fence
 * @property string|null $verdict_turn_item_id
 * @property int|null $verdict_turn_fence
 * @property string|null $correction_text
 * @property string|null $correction_steer_id
 * @property string|null $terminal_milestone_id
 * @property CarbonImmutable|null $verdict_at
 * @property-read WorkItem $workItem
 * @property-read WorkOrchestrationEvent|null $orchestrationEvent
 * @property-read WorkSteer|null $correctionSteer
 * @property-read WorkMilestone|null $terminalMilestone
 */
class WorkFinish extends Model
{
    use HasUuids;

    public const int WRITEUP_MAX_LENGTH = 4000;

    public const int CORRECTION_MAX_LENGTH = 4000;

    /** @var list<string> */
    protected $fillable = [
        'work_item_id', 'fence', 'writeup', 'pr', 'status', 'orchestration_event_id',
        'verdict_actor', 'verdict_spoke_id', 'verdict_seat_fence', 'verdict_turn_item_id',
        'verdict_turn_fence', 'correction_text', 'correction_steer_id', 'terminal_milestone_id',
        'verdict_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fence' => 'integer',
            'pr' => WorkPullRequest::class,
            'verdict_spoke_id' => 'integer',
            'verdict_seat_fence' => 'integer',
            'verdict_turn_fence' => 'integer',
            'verdict_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    /** @return BelongsTo<WorkOrchestrationEvent, $this> */
    public function orchestrationEvent(): BelongsTo
    {
        return $this->belongsTo(WorkOrchestrationEvent::class);
    }

    /** @return BelongsTo<WorkSteer, $this> */
    public function correctionSteer(): BelongsTo
    {
        return $this->belongsTo(WorkSteer::class);
    }

    /** @return BelongsTo<WorkMilestone, $this> */
    public function terminalMilestone(): BelongsTo
    {
        return $this->belongsTo(WorkMilestone::class);
    }
}
