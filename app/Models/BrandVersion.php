<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $brand_kit_key
 * @property int $version_number
 * @property array<string, mixed> $configuration
 * @property string $applied_by_user_id
 * @mixin IdeHelperBrandVersion
 */
class BrandVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'brand_kit_key',
        'version_number',
        'configuration',
        'applied_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Applied brand versions are immutable.'));
        static::deleting(static fn () => throw new LogicException('Applied brand versions are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'configuration' => 'array',
        ];
    }
}
