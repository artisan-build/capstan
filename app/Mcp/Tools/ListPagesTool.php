<?php

namespace App\Mcp\Tools;

use App\Features\Artifacts;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\SignedCursor;
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
use Illuminate\Support\Str;
use Laravel\Mcp\Exceptions\JsonRpcException;
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
        PageRepresentation $representation,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['limit', 'cursor']);

        if (! Feature::active(Artifacts::class)) {
            return Response::error('Pages are unavailable.');
        }

        $validated = $request->validate([
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'required', 'string', 'max:2048'],
        ]);
        $owner = $owners->resolve();
        $limit = (int) ($validated['limit'] ?? 10);
        $actorBinding = $owner->type.':'.$owner->ref;
        $queryBinding = hash('sha256', 'list_pages:v1:'.$limit);
        $after = isset($validated['cursor'])
            ? $cursors->decode($validated['cursor'], $actorBinding, $queryBinding)
            : null;
        $lastId = $after['last_id'] ?? null;

        if ($after !== null && (! is_string($lastId) || ! Str::isUuid($lastId))) {
            throw new JsonRpcException('The cursor is invalid for this request.', -32602);
        }

        $candidates = Page::query()
            ->where('owner_user_id', $owner->actorId)
            ->when($lastId !== null, fn ($query) => $query->where('id', '>', $lastId))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $pages = $candidates->take($limit);
        $items = $pages->map(fn (Page $page): array => $representation->listItem($page))->values()->all();
        $hasMore = $candidates->count() > $limit;
        $last = $pages->last();
        $nextCursor = $hasMore && $last instanceof Page
            ? $cursors->encode([
                'actor' => $actorBinding,
                'query' => $queryBinding,
                'last_id' => $last->id,
            ])
            : null;

        return ToolResponse::structured([
            'items' => $items,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'limit' => $limit,
        ]);
    }
}
