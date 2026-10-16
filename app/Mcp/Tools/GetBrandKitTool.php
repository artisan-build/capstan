<?php

namespace App\Mcp\Tools;

use App\Features\Artifacts;
use App\Mcp\OwnerSubjectResolver;
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
#[Description('Returns the applied and draft brand kit, immutable version history, and the complete page-authoring contract without asset bytes.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class GetBrandKitTool extends BrandTool
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
        BrandContract $contract,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, []);
        if (! Feature::active(Artifacts::class)) {
            return Response::error('Branding is unavailable.');
        }

        $this->brandActor($owners, $brands, false);

        return ToolResponse::structured([
            ...$brands->read(),
            'contract' => $contract->describe(),
        ]);
    }
}
