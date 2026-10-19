<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property int $spoke_id
 * @property int $team_id
 * @property bool|null $old_enabled
 * @property list<string>|null $old_roles
 * @property list<string>|null $old_capabilities
 * @property array<string, string>|null $old_role_providers
 * @property int|null $old_concurrency_ceiling
 * @property bool $new_enabled
 * @property list<string> $new_roles
 * @property list<string> $new_capabilities
 * @property array<string, string> $new_role_providers
 * @property int $new_concurrency_ceiling
 * @property string $updated_by_actor
 * @property CarbonImmutable $occurred_at
 * @property-read Spoke $spoke
 * @property-read Team $team
 */
class MachineWorkSettingChange extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'spoke_id',
        'team_id',
        'old_enabled',
        'old_roles',
        'old_capabilities',
        'old_role_providers',
        'old_concurrency_ceiling',
        'new_enabled',
        'new_roles',
        'new_capabilities',
        'new_role_providers',
        'new_concurrency_ceiling',
        'updated_by_actor',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_enabled' => 'boolean',
            'old_roles' => 'array',
            'old_capabilities' => 'array',
            'old_role_providers' => 'array',
            'old_concurrency_ceiling' => 'integer',
            'new_enabled' => 'boolean',
            'new_roles' => 'array',
            'new_capabilities' => 'array',
            'new_role_providers' => 'array',
            'new_concurrency_ceiling' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Machine Work setting history is append-only.'));
        static::deleting(static fn () => throw new LogicException('Machine Work setting history is append-only.'));
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
