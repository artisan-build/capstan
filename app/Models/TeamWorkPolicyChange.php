<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property string $actor
 * @property string $surface
 * @property bool $old_pool_work
 * @property bool $new_pool_work
 * @property CarbonImmutable $created_at
 * @property-read Team $team
 */
class TeamWorkPolicyChange extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'team_id',
        'actor',
        'surface',
        'old_pool_work',
        'new_pool_work',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_pool_work' => 'boolean',
            'new_pool_work' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
