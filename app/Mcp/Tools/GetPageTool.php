<?php

namespace App\Mcp\Tools;

use App\Features\Artifacts;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\SignedCursor;
use App\Mcp\ToolResponse;
use App\Models\Page;
use App\Models\PageVersion;
use App\Support\GetPageLimit;
use App\Support\PageRepresentation;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Exceptions\JsonRpcException;
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
            'version_limit' => $schema->integer()->min(1)->max(GetPageLimit::MAX)->default(GetPageLimit::DEFAULT),
            'version_cursor' => $schema->string()->max(2048),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        SignedCursor $cursors,
        PageRepresentation $representation,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['page_id', 'version_limit', 'version_cursor']);

        if (! Feature::active(Artifacts::class)) {
            return Response::error('Pages are unavailable.');
        }

        $validated = $request->validate([
            'page_id' => ['required', 'string', 'uuid'],
            'version_limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.GetPageLimit::MAX],
            'version_cursor' => ['sometimes', 'required', 'string', 'max:2048'],
        ]);
        $owner = $owners->resolve();
        $page = Page::query()
            ->whereKey($validated['page_id'])
            ->where('owner_user_id', $owner->actorId)
            ->with('latestVersion')
            ->first();

        if (! $page instanceof Page) {
            return Response::error('Page not found.');
        }

        $limit = (int) ($validated['version_limit'] ?? GetPageLimit::DEFAULT);
        $actorBinding = $owner->type.':'.$owner->ref;
        $queryBinding = hash('sha256', 'get_page_versions:v1:'.$page->id.':'.$limit);
        $after = isset($validated['version_cursor'])
            ? $cursors->decode($validated['version_cursor'], $actorBinding, $queryBinding)
            : null;
        $lastVersionNumber = $after['last_version_number'] ?? null;

        if ($after !== null && (! is_int($lastVersionNumber) || $lastVersionNumber < 1)) {
            throw new JsonRpcException('The cursor is invalid for this request.', -32602);
        }

        $candidates = PageVersion::query()
            ->where('page_id', $page->id)
            ->when($lastVersionNumber !== null, fn ($query) => $query->where('version_number', '>', $lastVersionNumber))
            ->orderBy('version_number')
            ->limit($limit + 1)
            ->get();
        $versions = $candidates->take($limit);
        $hasMore = $candidates->count() > $limit;
        $last = $versions->last();
        $nextCursor = $hasMore && $last instanceof PageVersion
            ? $cursors->encode([
                'actor' => $actorBinding,
                'query' => $queryBinding,
                'last_version_number' => $last->version_number,
            ])
            : null;
        $detail = $representation->detail($page, $versions, $nextCursor, $hasMore, $limit);

        if (! is_string($detail['content']) || ! ToolResponse::fits(['page' => $detail])) {
            return Response::error('Page content is unavailable.');
        }

        return ToolResponse::structured(['page' => $detail]);
    }
}
