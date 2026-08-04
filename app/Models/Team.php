<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\UniqueConstraintViolationException;

class Team extends Model
{
    public const DEFAULT_SLUG = 'default';

    protected $fillable = [
        'name',
        'slug',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
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
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Artifact, $this>
     */
    public function artifacts(): BelongsToMany
    {
        return $this->belongsToMany(Artifact::class)->withTimestamps();
    }
}
