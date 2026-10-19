<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $machine_usage_report_id
 * @property string $provider
 * @property string|null $plan
 * @property CarbonImmutable $fetched_at
 * @property bool|null $source_stale
 * @property CarbonImmutable|null $expires_at
 * @property string $status
 * @property-read MachineUsageReport $report
 * @property-read Collection<int, MachineUsageWindow> $windows
 */
class MachineUsageProvider extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'machine_usage_report_id',
        'provider',
        'plan',
        'fetched_at',
        'source_stale',
        'expires_at',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fetched_at' => 'immutable_datetime',
            'source_stale' => 'boolean',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<MachineUsageReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(MachineUsageReport::class, 'machine_usage_report_id');
    }

    /** @return HasMany<MachineUsageWindow, $this> */
    public function windows(): HasMany
    {
        return $this->hasMany(MachineUsageWindow::class);
    }
}
