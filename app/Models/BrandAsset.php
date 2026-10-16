<?php

namespace App\Models;

use App\Enums\BrandAssetKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property BrandAssetKind $kind
 * @property string $content_type
 * @property int $size_bytes
 * @property string $content_hash
 * @property string $storage_key
 * @property string $created_by_user_id
 *
 * @mixin IdeHelperBrandAsset
 */
class BrandAsset extends Model
{
    use HasUuids;

    protected $fillable = [
        'kind',
        'content_type',
        'size_bytes',
        'content_hash',
        'storage_key',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => BrandAssetKind::class,
            'size_bytes' => 'integer',
        ];
    }
}
