<?php

namespace App\Support;

use App\Enums\ArtifactVisibility;
use App\Models\Artifact;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

final readonly class ArtifactCreator
{
    public function __construct(private ArtifactRenderOrigin $renderOrigin) {}

    public function create(
        string $actorId,
        string $content,
        string $contentType,
        ArtifactVisibility $visibility,
        ?string $expiresAt,
        ?string $id = null,
    ): Artifact {
        [$contentHash, $storageKey] = Artifact::storeBlob($content);
        $defaultTeam = Team::default();

        return DB::transaction(function () use ($actorId, $content, $contentType, $visibility, $expiresAt, $id, $contentHash, $storageKey, $defaultTeam): Artifact {
            $artifact = new Artifact;
            $artifact->forceFill([
                'id' => $id,
                'actor_id' => $actorId,
                'visibility' => $visibility,
                'expires_at' => $expiresAt,
                'content_type' => $contentType,
                'size_bytes' => strlen($content),
                'content_hash' => $contentHash,
                'storage_key' => $storageKey,
            ]);
            $artifact->save();
            $artifact->teams()->syncWithoutDetaching([$defaultTeam->id]);

            return $artifact;
        });
    }

    /** @return array<string, mixed> */
    public function representation(Artifact $artifact): array
    {
        return [
            'id' => $artifact->id,
            'actor_id' => $artifact->actor_id,
            'visibility' => $artifact->visibility->value,
            'expires_at' => $artifact->expires_at?->toJSON(),
            'content_type' => $artifact->content_type,
            'size_bytes' => $artifact->size_bytes,
            'content_hash' => $artifact->content_hash,
            'share_url' => $this->renderOrigin->signedViewerUrl($artifact),
            'created_at' => $artifact->created_at?->toJSON(),
        ];
    }
}
