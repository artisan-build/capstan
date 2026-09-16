<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A claimed local part. Exactly one user owns a local part; any number of that
 * user's spokes may route for it (a pool). Ownership persists after every spoke
 * stops advertising the inbox.
 *
 * @property int $id
 * @property string $actor_id
 * @property string $local_part
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Inbox extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'actor_id',
        'local_part',
    ];

    /** @return BelongsToMany<Spoke, $this> */
    public function spokes(): BelongsToMany
    {
        return $this->belongsToMany(Spoke::class, 'spoke_inboxes')->withTimestamps();
    }
}
