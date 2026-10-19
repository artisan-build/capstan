<?php

namespace App\Models;

use App\Machine\MachineRecordBounds;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $machine_usage_provider_id
 * @property string $slot
 * @property string $kind
 * @property int|null $window_seconds
 * @property string $remaining_percent
 * @property CarbonImmutable|null $resets_at
 * @property string $window_identity
 * @property-read MachineUsageProvider $provider
 *
 * @mixin IdeHelperMachineUsageWindow
 */
class MachineUsageWindow extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'machine_usage_provider_id',
        'slot',
        'kind',
        'window_seconds',
        'remaining_percent',
        'resets_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'window_seconds' => 'integer',
            'remaining_percent' => 'decimal:3',
            'resets_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $window): void {
            $window->setAttribute('window_identity', MachineRecordBounds::window(
                $window->getAttributes(),
                $window->exists ? (int) $window->getKey() : null,
            ));
        });
    }

    /** @return BelongsTo<MachineUsageProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(MachineUsageProvider::class, 'machine_usage_provider_id');
    }
}
