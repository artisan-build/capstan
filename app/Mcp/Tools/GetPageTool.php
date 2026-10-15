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

#[Name('get_page')]
#[Description('Reads one owned page, its refresh recipe, ordered immutable versions, and current HTML.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class GetPageTool extends CapstanTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->required(),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        PageRepresentation $representation,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['page_id']);

        if (! Feature::active(Artifacts::class)) {
            return Response::error('Pages are unavailable.');
        }

        $validated = $request->validate(['page_id' => ['required', 'string', 'uuid']]);
        $owner = $owners->resolve();
        $page = Page::query()
            ->whereKey($validated['page_id'])
            ->where('owner_user_id', $owner->actorId)
            ->with(['latestVersion', 'versions'])
            ->first();

        if (! $page instanceof Page) {
            return Response::error('Page not found.');
        }

        $detail = $representation->detail($page);

        if (! is_string($detail['content']) || ! ToolResponse::fits(['page' => $detail])) {
            return Response::error('Page content is unavailable.');
        }

        return ToolResponse::structured(['page' => $detail]);
    }
}
