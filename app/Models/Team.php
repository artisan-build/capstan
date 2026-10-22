<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * @property int $work_preemption_revision
 *
 * @mixin IdeHelperTeam
 */
class Team extends Model
{
    public const DEFAULT_SLUG = 'default';

    protected $fillable = [
        'name',
        'slug',
        'is_default',
        'pool_work',
        'default_failover',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'pool_work' => 'boolean',
            'default_failover' => 'boolean',
            'work_preemption_revision' => 'integer',
        ];
    }

    public static function default(): self
    {
        try {
            return self::query()->firstOrCreate(
                ['slug' => self::DEFAULT_SLUG],
                ['name' => 'Default', 'is_default' => true],
            );
        } catch (UniqueConstraintViolationException) {
            return self::query()->where('slug', self::DEFAULT_SLUG)->firstOrFail();
        }
    }

    /**
     * @return BelongsToMany<Artifact, $this>
     */
    public function artifacts(): BelongsToMany
    {
        return $this->belongsToMany(Artifact::class)->withTimestamps();
    }

    /** @return HasMany<WorkTask, $this> */
    public function workTasks(): HasMany
    {
        return $this->hasMany(WorkTask::class);
    }
}
