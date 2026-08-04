<?php

namespace Database\Factories;

use App\Enums\ArtifactVisibility;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Artifact>
 */
class ArtifactFactory extends Factory
{
    public function definition(): array
    {
        $content = '<!doctype html><html><body>Artifact</body></html>';
        $contentHash = hash('sha256', $content);

        return [
            'author_id' => User::factory(),
            'visibility' => ArtifactVisibility::OrgAuth,
            'expires_at' => null,
            'content_type' => 'text/html',
            'size_bytes' => strlen($content),
            'content_hash' => $contentHash,
            'storage_key' => Artifact::storageKeyForHash($contentHash),
        ];
    }
}
