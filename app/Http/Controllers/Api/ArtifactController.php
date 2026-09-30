<?php

namespace App\Http\Controllers\Api;

use App\Enums\ArtifactVisibility;
use App\Features\Artifacts;
use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateBoundCredential;
use App\Support\ArtifactCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

class ArtifactController extends Controller
{
    public function store(
        Request $request,
        ArtifactCreator $creator,
    ): JsonResponse {
        $actorId = AuthenticateBoundCredential::actorId($request);

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
        $artifact = $creator->create(
            actorId: $actorId,
            content: $validated['content'],
            contentType: $validated['content_type'],
            visibility: ArtifactVisibility::from($validated['visibility'] ?? ArtifactVisibility::OrgAuth->value),
            expiresAt: $validated['expires_at'] ?? null,
        );
        $representation = $creator->representation($artifact);

        return new JsonResponse([
            'artifact' => $representation,
            'share_url' => $representation['share_url'],
        ], 201);
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
