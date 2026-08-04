<?php

namespace App\Models;

use App\Enums\ArtifactVisibility;
use Database\Factories\ArtifactFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int|null $author_id
 * @property ArtifactVisibility $visibility
 * @property Carbon|null $expires_at
 * @property string $content_type
 * @property int $size_bytes
 * @property string $content_hash
 * @property string $storage_key
 */
#[UseFactory(ArtifactFactory::class)]
class Artifact extends Model
{
    /** @use HasFactory<ArtifactFactory> */
    use HasFactory;

    protected $fillable = [
        'author_id',
        'visibility',
        'expires_at',
        'content_type',
        'size_bytes',
        'content_hash',
        'storage_key',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => ArtifactVisibility::class,
            'expires_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public static function storageKeyForHash(string $contentHash): string
    {
        return 'artifacts/'.$contentHash;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function storeBlob(string $content): array
    {
        $contentHash = hash('sha256', $content);
        $storageKey = self::storageKeyForHash($contentHash);
        $disk = Storage::disk();

        if (! $disk->exists($storageKey)) {
            $disk->put($storageKey, $content, ['visibility' => 'private']);
        }

        return [$contentHash, $storageKey];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }
}
