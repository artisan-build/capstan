<?php

namespace App\Mcp\Tools;

use App\Features\Artifacts;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Models\Page;
use App\Support\PageRepresentation;
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

#[Name('list_pages')]
#[Description('Lists only the authenticated principal owned private pages.')]
#[IsReadOnly]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class ListPagesTool extends CapstanTool
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
        PageRepresentation $representation,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, []);

        if (! Feature::active(Artifacts::class)) {
            return Response::error('Pages are unavailable.');
        }

        $owner = $owners->resolve();
        $pages = Page::query()
            ->where('owner_user_id', $owner->actorId)
            ->with('latestVersion')
            ->latest('updated_at')
            ->orderBy('id')
            ->get();
        $items = [];

        foreach ($pages as $page) {
            $item = $representation->summary($page);

            if (! ToolResponse::fits(['items' => [...$items, $item], 'truncated' => true])) {
                break;
            }

            $items[] = $item;
        }

        return ToolResponse::structured([
            'items' => $items,
            'truncated' => count($items) < $pages->count(),
        ]);
    }
}
