<?php

namespace App\Models;

use App\Enums\SpokeLiveness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $token_id
 * @property string|null $name
 * @property CarbonImmutable|null $last_polled_at
 * @property string|null $last_cursor
 * @property SpokeLiveness $probe_status
 * @property CarbonImmutable|null $probe_failed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Spoke extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'token_id',
        'name',
        'last_polled_at',
        'last_cursor',
        'probe_status',
        'probe_failed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_polled_at' => 'datetime',
            'probe_status' => SpokeLiveness::class,
            'probe_failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The inboxes this spoke is currently ready to receive for.
     *
     * @return BelongsToMany<Inbox, $this>
     */
    public function inboxes(): BelongsToMany
    {
        return $this->belongsToMany(Inbox::class, 'spoke_inboxes')->withTimestamps();
    }

    /** @return HasMany<SpokeProbe, $this> */
    public function probes(): HasMany
    {
        return $this->hasMany(SpokeProbe::class);
    }
}
