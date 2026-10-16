<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $installation_key
 * @property string|null $current_version_id
 * @property int $current_version_number
 * @property array<string, mixed>|null $draft
 *
 * @mixin IdeHelperBrandKit
 */
class BrandKit extends Model
{
    protected $primaryKey = 'installation_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'installation_key',
        'current_version_id',
        'current_version_number',
        'draft',
    ];

    protected function casts(): array
    {
        return [
            'current_version_number' => 'integer',
            'draft' => 'array',
        ];
    }

    /** @return HasMany<BrandVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(BrandVersion::class, 'brand_kit_key', 'installation_key');
    }
}
