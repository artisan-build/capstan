<?php

namespace App\Models;

use App\Enums\ProbeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $spoke_id
 * @property string $probe_id
 * @property string $nonce
 * @property ProbeStatus $status
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $responded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class SpokeProbe extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'spoke_id',
        'probe_id',
        'nonce',
        'status',
        'issued_at',
        'expires_at',
        'responded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProbeStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Spoke, $this> */
    public function spoke(): BelongsTo
    {
        return $this->belongsTo(Spoke::class);
    }
}
