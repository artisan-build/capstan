<?php

namespace App\Models;

use App\Enums\SpokeLiveness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $actor_id
 * @property string $credential_id
 * @property bool $credential_backed
 * @property string|null $name
 * @property CarbonImmutable|null $last_polled_at
 * @property CarbonImmutable|null $work_heartbeat_notified_at
 * @property string|null $last_cursor
 * @property SpokeLiveness $probe_status
 * @property CarbonImmutable|null $probe_failed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read int|null $inboxes_count
 *
 * @mixin IdeHelperSpoke
 */
class Spoke extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'actor_id',
        'credential_id',
        'credential_backed',
        'name',
        'last_polled_at',
        'work_heartbeat_notified_at',
        'last_cursor',
        'probe_status',
        'probe_failed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credential_backed' => 'boolean',
            'last_polled_at' => 'datetime',
            'work_heartbeat_notified_at' => 'datetime',
            'probe_status' => SpokeLiveness::class,
            'probe_failed_at' => 'datetime',
        ];
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
