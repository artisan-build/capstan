<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $spoke_id
 * @property string $source
 * @property string|null $source_version
 * @property string $availability
 * @property CarbonImmutable|null $collected_at
 * @property CarbonImmutable $last_attempt_at
 * @property CarbonImmutable|null $last_success_received_at
 * @property string|null $failure_code
 * @property-read Spoke $spoke
 * @property-read Collection<int, MachineUsageProvider> $providers
 *
 * @mixin IdeHelperMachineUsageReport
 */
class MachineUsageReport extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'spoke_id',
        'source',
        'source_version',
        'availability',
        'collected_at',
        'last_attempt_at',
        'last_success_received_at',
        'failure_code',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'collected_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'last_success_received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Spoke, $this> */
    public function spoke(): BelongsTo
    {
        return $this->belongsTo(Spoke::class);
    }

    /** @return HasMany<MachineUsageProvider, $this> */
    public function providers(): HasMany
    {
        return $this->hasMany(MachineUsageProvider::class);
    }
}
