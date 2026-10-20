<?php

namespace App\Models;

use App\Enums\PageLayout;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $page_id
 * @property int $version_number
 * @property string $content_type
 * @property int $size_bytes
 * @property string $content_hash
 * @property string $storage_key
 * @property string $publisher_user_id
 * @property string $publisher_source
 * @property string $publisher_tool
 * @property string $refresh_recipe
 * @property PageLayout $layout
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Page $page
 * @mixin IdeHelperPageVersion
 */
class PageVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'page_id',
        'version_number',
        'content_type',
        'size_bytes',
        'content_hash',
        'storage_key',
        'publisher_user_id',
        'publisher_source',
        'publisher_tool',
        'refresh_recipe',
        'layout',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'size_bytes' => 'integer',
            'layout' => PageLayout::class,
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
