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
#[Description('Idempotently applies the explicitly previewed draft fingerprint as a new immutable brand version after user approval. Owner or Admin only.')]
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
            'draft_fingerprint' => $schema->string()->min(64)->max(64)->required(),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        DurableWriteCoordinator $writes,
        BrandKitService $brands,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['idempotency_key', 'draft_fingerprint']);
        if (! Feature::active(Artifacts::class)) {
            return Response::error('Branding is unavailable.');
        }

        [$owner, $actor] = $this->brandActor($owners, $brands, true);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            'draft_fingerprint' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'],
        ]);
        $fingerprint = (string) $validated['draft_fingerprint'];
        $outcome = $writes->run(
            owner: $owner,
            tool: 'apply_brand_draft',
            idempotencyKey: (string) $validated['idempotency_key'],
            intent: ['action' => 'apply_brand_draft', 'draft_fingerprint' => $fingerprint],
            targetType: 'brand_version',
            operation: function () use ($actor, $brands, $fingerprint): array {
                try {
                    $version = $brands->applyDraft($actor, $fingerprint);
                } catch (ValidationException $exception) {
                    if (array_key_exists('draft_fingerprint', $exception->errors())) {
                        throw new ApiErrorException(409, 'brand_draft_changed', 'The brand draft changed.');
                    }

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
