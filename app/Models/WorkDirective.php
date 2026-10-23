<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $work_item_id
 * @property int $claimant_spoke_id
 * @property int $fence
 * @property int $sequence
 * @property string $kind
 * @property string $reason
 * @property array<string, mixed> $payload
 * @property string $status
 * @property array<string, mixed>|null $outcome
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable|null $completed_at
 * @property-read WorkItem $workItem
 *
 * @mixin IdeHelperWorkDirective
 */
class WorkDirective extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'work_item_id', 'claimant_spoke_id', 'fence', 'sequence', 'kind', 'reason',
        'payload', 'status', 'outcome', 'issued_at', 'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'claimant_spoke_id' => 'integer',
            'fence' => 'integer',
            'sequence' => 'integer',
            'payload' => 'array',
            'outcome' => 'array',
            'issued_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }
}
