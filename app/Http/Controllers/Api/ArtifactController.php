<?php

namespace App\Http\Controllers\Api;

use App\Enums\ArtifactVisibility;
use App\Features\Artifacts;
use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Artifact;
use App\Models\Team;
use App\Models\User;
use App\Support\ArtifactRenderOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

class ArtifactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! Feature::active(Artifacts::class)) {
            return ApiError::notFound();
        }

        $validator = Validator::make($request->all(), [
            'content' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > $this->maxContentBytes()) {
                    $fail(__('The :attribute field must not be greater than :max bytes.', [
                        'attribute' => $attribute,
                        'max' => $this->maxContentBytes(),
                    ]));
                }
            }],
            'content_type' => ['required', 'string', Rule::in($this->allowedContentTypes())],
            'visibility' => ['sometimes', 'string', Rule::in(ArtifactVisibility::values())],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        if ($validator->fails()) {
            return ApiError::response(422, 'validation_failed', __('The given data was invalid.'), [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        /** @var array{content: string, content_type: string, visibility?: string, expires_at?: string|null} $validated */
        $validated = $validator->validated();
        /** @var User $user */
        $user = Auth::user();
        $defaultTeam = Team::default();

        [$contentHash, $storageKey] = Artifact::storeBlob($validated['content']);

        $artifact = DB::transaction(function () use ($validated, $user, $defaultTeam, $contentHash, $storageKey): Artifact {
            $artifact = Artifact::query()->create([
                'author_id' => $user->id,
                'visibility' => ArtifactVisibility::from($validated['visibility'] ?? ArtifactVisibility::OrgAuth->value),
                'expires_at' => $validated['expires_at'] ?? null,
                'content_type' => $validated['content_type'],
                'size_bytes' => strlen($validated['content']),
                'content_hash' => $contentHash,
                'storage_key' => $storageKey,
            ]);

            $artifact->teams()->syncWithoutDetaching([$defaultTeam->id]);

            return $artifact;
        });

        return new JsonResponse([
            'artifact' => $this->representation($artifact),
            'share_url' => app(ArtifactRenderOrigin::class)->signedViewerUrl($artifact),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function representation(Artifact $artifact): array
    {
        return [
            'id' => $artifact->id,
            'author_id' => $artifact->author_id,
            'visibility' => $artifact->visibility->value,
            'expires_at' => $artifact->expires_at?->toJSON(),
            'content_type' => $artifact->content_type,
            'size_bytes' => $artifact->size_bytes,
            'content_hash' => $artifact->content_hash,
            'share_url' => app(ArtifactRenderOrigin::class)->signedViewerUrl($artifact),
            'created_at' => $artifact->created_at?->toJSON(),
        ];
    }

    private function maxContentBytes(): int
    {
        return (int) config('capstan.artifacts.max_content_bytes');
    }

    /**
     * @return list<string>
     */
    private function allowedContentTypes(): array
    {
        $contentTypes = config('capstan.artifacts.allowed_content_types');

        return is_array($contentTypes) ? array_values($contentTypes) : [];
    }
}
