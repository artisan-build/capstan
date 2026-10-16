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

#[Name('list_layouts')]
#[Description('Lists the four supported page layouts and the component and chrome contract used by each.')]
#[IsReadOnly]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class ListLayoutsTool extends BrandTool
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
        $description = $contract->describe();

        return ToolResponse::structured([
            'layouts' => $description['layouts'],
            'component_classes' => $description['component_classes'],
            'selection_guidance' => 'Use plain for fully custom documents, report for long-form work, dashboard for dense metrics, and brief for compact summaries.',
        ]);
    }
}
