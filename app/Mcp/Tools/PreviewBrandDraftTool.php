<?php

namespace App\Mcp\Tools;

use App\Features\Artifacts;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Support\ArtifactRenderOrigin;
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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

#[Name('preview_brand_draft')]
#[Description('Returns the current draft fingerprint and a temporary signed render-origin URL demonstrating it in all four layouts.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class PreviewBrandDraftTool extends BrandTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        BrandKitService $brands,
        ArtifactRenderOrigin $origin,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, []);
        if (! Feature::active(Artifacts::class) || ! $origin->isConfigured()) {
            return Response::error('Branding is unavailable.');
        }

        $this->brandActor($owners, $brands, true);
        $fingerprint = $brands->draftFingerprint();
        if ($fingerprint === null) {
            throw ValidationException::withMessages(['draft' => 'There is no brand draft to preview.']);
        }

        return ToolResponse::structured([
            'draft_fingerprint' => $fingerprint,
            'preview_url' => $origin->signedBrandPreviewUrl($fingerprint),
            'expires_in_seconds' => 300,
            'layouts' => ['plain', 'report', 'dashboard', 'brief'],
        ]);
    }
}
