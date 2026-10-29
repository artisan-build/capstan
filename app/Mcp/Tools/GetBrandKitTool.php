<?php

namespace App\Mcp\Tools;

use App\Features\Artifacts;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\SignedCursor;
use App\Mcp\ToolResponse;
use App\Support\BrandContract;
use App\Support\BrandKitService;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

#[Name('get_brand_kit')]
#[Description('Returns the applied and draft brand kit, a bounded page of immutable version metadata, and the complete page-authoring contract without asset bytes.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class GetBrandKitTool extends BrandTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public const int MAX_LIMIT = 25;

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->default(10),
            'cursor' => $schema->string()->max(2048),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        SignedCursor $cursors,
        BrandKitService $brands,
        BrandContract $contract,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['limit', 'cursor']);
        if (! Feature::active(Artifacts::class)) {
            return Response::error('Branding is unavailable.');
        }

        $validated = $request->validate([
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'required', 'string', 'max:2048'],
        ]);
        [$owner] = $this->brandActor($owners, $brands, false);
        $limit = (int) ($validated['limit'] ?? 10);
        $actorBinding = $owner->type.':'.$owner->ref;
        $queryBinding = hash('sha256', 'get_brand_kit:v1:'.$limit);
        $after = isset($validated['cursor'])
            ? $cursors->decode($validated['cursor'], $actorBinding, $queryBinding)
            : null;
        $lastVersionNumber = $after['last_version_number'] ?? null;

        if ($after !== null && (! is_int($lastVersionNumber) || $lastVersionNumber < 1)) {
            throw $cursors->invalid();
        }

        $history = $brands->versionHistoryPage($limit, $lastVersionNumber);
        $nextCursor = $history['has_more'] && $history['last_version_number'] !== null
            ? $cursors->encode([
                'actor' => $actorBinding,
                'query' => $queryBinding,
                'last_version_number' => $history['last_version_number'],
            ])
            : null;
        $payload = [
            ...$brands->readCurrentState(),
            'versions' => $history['versions'],
            'next_cursor' => $nextCursor,
            'has_more' => $history['has_more'],
            'limit' => $limit,
            'contract' => $contract->describe(),
        ];

        if (! ToolResponse::fits($payload)) {
            return Response::error('The brand kit exceeds the response budget.');
        }

        return ToolResponse::structured($payload);
    }
}
