<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $spoke_id
 * @property int $team_id
 * @property list<string> $roles
 * @property list<string> $capabilities
 * @property array<string, string> $role_providers
 * @property int $current_claimed_item_count
 * @property int $concurrency_ceiling
 * @property CarbonImmutable $observed_at
 * @property-read Spoke $spoke
 * @property-read Team $team
 *
 * @mixin IdeHelperMachineWorkPresence
 */
class MachineWorkPresence extends Model
{
    protected $table = 'machine_work_presence';

    /** @var list<string> */
    protected $fillable = [
        'spoke_id',
        'team_id',
        'roles',
        'capabilities',
        'role_providers',
        'current_claimed_item_count',
        'concurrency_ceiling',
        'observed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'capabilities' => 'array',
            'role_providers' => 'array',
            'current_claimed_item_count' => 'integer',
            'concurrency_ceiling' => 'integer',
            'observed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Spoke, $this> */
    public function spoke(): BelongsTo
    {
        return $this->belongsTo(Spoke::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
