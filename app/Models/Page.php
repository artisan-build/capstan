<?php

namespace App\Models;

use App\Enums\PageLayout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $owner_user_id
 * @property string $title
 * @property string|null $description
 * @property string $refresh_recipe
 * @property PageLayout $layout
 * @property string|null $latest_version_id
 * @property int $version_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PageVersion|null $latestVersion
 * @property-read Collection<int, PageVersion> $versions
 * @property-read Collection<int, PageShare> $shares
 *
 * @mixin IdeHelperPage
 */
class Page extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_user_id',
        'title',
        'description',
        'refresh_recipe',
        'layout',
        'latest_version_id',
        'version_count',
    ];

    protected function casts(): array
    {
        return [
            'layout' => PageLayout::class,
            'version_count' => 'integer',
        ];
    }

    /** @return BelongsTo<PageVersion, $this> */
    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'latest_version_id');
    }

    /** @return HasMany<PageVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class)->orderBy('version_number');
    }

    /** @return HasMany<PageShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(PageShare::class)->orderByDesc('created_at');
    }
}
