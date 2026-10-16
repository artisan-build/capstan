<?php

namespace App\Mcp\Tools;

use App\Features\Artifacts;
use App\Http\ApiErrorException;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Support\BrandKitService;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

#[Name('apply_brand_draft')]
#[Description('Idempotently applies the current draft as a new immutable brand version after explicit user approval. Owner or Admin only.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class ApplyBrandDraftTool extends BrandTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        DurableWriteCoordinator $writes,
        BrandKitService $brands,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['idempotency_key']);
        if (! Feature::active(Artifacts::class)) {
            return Response::error('Branding is unavailable.');
        }

        [$owner, $actor] = $this->brandActor($owners, $brands, true);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ]);
        $outcome = $writes->run(
            owner: $owner,
            tool: 'apply_brand_draft',
            idempotencyKey: (string) $validated['idempotency_key'],
            intent: ['action' => 'apply_current_brand_draft'],
            targetType: 'brand_version',
            operation: function () use ($actor, $brands): array {
                try {
                    $version = $brands->applyDraft($actor);
                } catch (ValidationException) {
                    throw new ApiErrorException(409, 'brand_draft_unavailable', 'The brand draft is unavailable.');
                }

                return ['version' => [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                    'applied_at' => $version->created_at?->toIso8601String(),
                ]];
            },
        );

        return ToolResponse::structured($outcome);
    }
}
