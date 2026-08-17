<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $spoke_id
 * @property string $local_part
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class SpokeInbox extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'spoke_id',
        'local_part',
    ];

    /** @return BelongsTo<Spoke, $this> */
    public function spoke(): BelongsTo
    {
        return $this->belongsTo(Spoke::class);
    }
}
