<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $spoke_id
 * @property int $team_id
 * @property bool $enabled
 * @property list<string> $roles
 * @property list<string> $capabilities
 * @property array<string, string> $role_providers
 * @property int $concurrency_ceiling
 * @property string $updated_by_actor
 * @property-read Spoke $spoke
 * @property-read Team $team
 */
class MachineTeamWorkSetting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'spoke_id',
        'team_id',
        'enabled',
        'roles',
        'capabilities',
        'role_providers',
        'concurrency_ceiling',
        'updated_by_actor',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'roles' => 'array',
            'capabilities' => 'array',
            'role_providers' => 'array',
            'concurrency_ceiling' => 'integer',
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
