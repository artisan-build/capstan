<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $spoke_id
 * @property string $finding_code
 * @property bool $active
 * @property string|null $transition_key
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property-read Spoke $spoke
 *
 * @mixin IdeHelperMachineConditionState
 */
class MachineConditionState extends Model
{
    protected $fillable = [
        'spoke_id',
        'finding_code',
        'active',
        'transition_key',
        'opened_at',
        'closed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Spoke, $this> */
    public function spoke(): BelongsTo
    {
        return $this->belongsTo(Spoke::class);
    }
}
