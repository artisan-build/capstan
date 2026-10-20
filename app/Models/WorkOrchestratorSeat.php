<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $work_task_id
 * @property string|null $holder_actor
 * @property int|null $holder_spoke_id
 * @property string|null $session_ref
 * @property bool $interactive
 * @property CarbonImmutable|null $lease_until
 * @property int $fence
 * @property bool $failover
 * @property CarbonImmutable|null $waiting_at
 * @property CarbonImmutable|null $notified_at
 * @property string|null $active_item_id
 * @property int $event_sequence
 * @property int $completed_cursor
 * @property int $turns_completed
 * @property int $turns_started
 * @property int $children_created
 * @property CarbonImmutable|null $stopped_at
 * @mixin IdeHelperWorkOrchestratorSeat
 */
class WorkOrchestratorSeat extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'holder_spoke_id' => 'integer', 'interactive' => 'boolean', 'lease_until' => 'datetime',
            'fence' => 'integer', 'failover' => 'boolean', 'waiting_at' => 'datetime', 'notified_at' => 'datetime',
            'event_sequence' => 'integer', 'completed_cursor' => 'integer', 'turns_completed' => 'integer',
            'turns_started' => 'integer',
            'children_created' => 'integer', 'stopped_at' => 'datetime',
        ];
    }
}
