<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $token_id
 * @property string|null $name
 * @property CarbonImmutable|null $last_polled_at
 * @property string|null $last_cursor
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
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_polled_at' => 'datetime',
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
}
