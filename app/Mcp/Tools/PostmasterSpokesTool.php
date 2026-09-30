<?php

namespace App\Mcp\Tools;

use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Features\Postmaster;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\SignedCursor;
use App\Mcp\ToolResponse;
use App\Models\Spoke;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;
use Throwable;

#[Name('postmaster_spokes')]
#[Description('Lists the authenticated actor owned Postmaster spokes and currently routed inboxes in stable health order.')]
#[IsReadOnly]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class PostmasterSpokesTool extends CapstanTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public const int MAX_LIMIT = 25;

    private const int SNAPSHOT_TTL_SECONDS = 86_400;

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->default(10),
            'cursor' => $schema->string()->max(2048),
        ];
    }

    public function handle(Request $request, OwnerSubjectResolver $owners, SignedCursor $cursors): Response|ResponseFactory
    {
        $this->requireExactArguments($request, ['limit', 'cursor']);

        if (! Feature::active(Postmaster::class)) {
            return Response::error('Postmaster is unavailable.');
        }

        $validated = $request->validate([
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'required', 'string', 'max:2048'],
        ]);
        $owner = $owners->resolve();
        $limit = (int) ($validated['limit'] ?? 10);
        $actorBinding = $owner->type.':'.$owner->ref;
        $staleAfter = max(60, (int) config('capstan.postmaster.map.stale_after_seconds', 300));
        $queryBinding = hash('sha256', 'postmaster_spokes:v1:'.$staleAfter.':'.$limit);
        $after = isset($validated['cursor'])
            ? $cursors->decode($validated['cursor'], $actorBinding, $queryBinding)
            : null;
        $staleBefore = $after === null
            ? now()->subSeconds($staleAfter)
            : $this->cursorStaleBefore($after);

        $currentSpokes = Spoke::query()
            ->where('actor_id', $owner->actorId)
            ->with(['inboxes' => fn ($query) => $query->where('actor_id', $owner->actorId)->orderBy('local_part')])
            ->get();

        // Freeze traversal order while rendering each spoke's current health and routing details.
        [$snapshotId, $snapshotIds, $offset] = $after === null
            ? [Str::random(40), $currentSpokes
                ->sort(fn (Spoke $first, Spoke $second): int => $this->compareSortKeys(
                    $this->sortKey($first, $staleBefore),
                    $this->sortKey($second, $staleBefore),
                ))
                ->pluck('id')
                ->values()
                ->all(), 0]
            : $this->cursorSnapshot($after, $actorBinding, $queryBinding);
        $spokesById = $currentSpokes->keyBy('id');

        $items = [];
        $nextOffset = $offset;

        foreach (array_slice($snapshotIds, $offset) as $spokeId) {
            if (count($items) >= $limit) {
                break;
            }

            $nextOffset++;
            $spoke = $spokesById->get($spokeId);

            if (! $spoke instanceof Spoke) {
                continue;
            }

            $item = [
                'id' => $spoke->id,
                'name' => $this->displayName($spoke),
                'last_polled_at' => $spoke->last_polled_at?->toJSON(),
                'inboxes_count' => $spoke->inboxes->count(),
                'inboxes' => $spoke->inboxes->pluck('local_part')->values()->all(),
                'probe_status' => $spoke->probe_status->value,
                'status' => $this->mapStatus($spoke, $staleBefore)->value,
            ];

            if (! ToolResponse::fits(['items' => [...$items, $item], 'next_cursor' => str_repeat('x', 2048)])) {
                $nextOffset--;
                break;
            }

            $items[] = $item;
        }

        $hasMore = $nextOffset < count($snapshotIds);

        if ($hasMore) {
            Cache::put($this->snapshotKey($snapshotId), [
                'actor' => $actorBinding,
                'query' => $queryBinding,
                'ids' => $snapshotIds,
            ], self::SNAPSHOT_TTL_SECONDS);
            $nextCursor = $cursors->encode([
                'actor' => $actorBinding,
                'query' => $queryBinding,
                'stale_before' => $staleBefore->toISOString(),
                'snapshot' => $snapshotId,
                'offset' => $nextOffset,
            ]);
        } else {
            $nextCursor = null;
        }

        return ToolResponse::structured([
            'items' => $items,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'limit' => $limit,
            'stale_after_seconds' => $staleAfter,
        ]);
    }

    /** @return array{int, string, int} */
    private function sortKey(Spoke $spoke, CarbonInterface $staleBefore): array
    {
        return [
            $this->mapStatus($spoke, $staleBefore) === SpokeMapStatus::Red ? 0 : 1,
            $this->displayName($spoke),
            $spoke->id,
        ];
    }

    /**
     * @param  array{int, string, int}  $left
     * @param  array{int, string, int}  $right
     */
    private function compareSortKeys(array $left, array $right): int
    {
        $status = $left[0] <=> $right[0];

        if ($status !== 0) {
            return $status;
        }

        $name = strnatcasecmp($left[1], $right[1]);

        return $name !== 0 ? $name : $left[2] <=> $right[2];
    }

    /** @param array<string, mixed> $cursor */
    private function cursorStaleBefore(array $cursor): CarbonImmutable
    {
        $value = $cursor['stale_before'] ?? null;

        if (! is_string($value)) {
            throw new JsonRpcException('The cursor is invalid for this request.', -32602);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw new JsonRpcException('The cursor is invalid for this request.', -32602);
        }
    }

    /**
     * @param  array<string, mixed>  $cursor
     * @return array{string, list<int>, int}
     */
    private function cursorSnapshot(array $cursor, string $actorBinding, string $queryBinding): array
    {
        $snapshotId = $cursor['snapshot'] ?? null;
        $offset = $cursor['offset'] ?? null;

        if (! is_string($snapshotId)
            || preg_match('/\A[A-Za-z0-9]{40}\z/D', $snapshotId) !== 1
            || ! is_int($offset)
            || $offset < 0) {
            throw new JsonRpcException('The cursor is invalid for this request.', -32602);
        }

        $snapshot = Cache::get($this->snapshotKey($snapshotId));
        $ids = is_array($snapshot) ? ($snapshot['ids'] ?? null) : null;

        if (! is_array($snapshot)
            || ($snapshot['actor'] ?? null) !== $actorBinding
            || ($snapshot['query'] ?? null) !== $queryBinding
            || ! is_array($ids)
            || ! array_is_list($ids)
            || array_filter($ids, fn (mixed $id): bool => ! is_int($id)) !== []
            || $offset > count($ids)) {
            throw new JsonRpcException('The cursor is invalid for this request.', -32602);
        }

        return [$snapshotId, $ids, $offset];
    }

    private function snapshotKey(string $snapshotId): string
    {
        return 'capstan:mcp:postmaster-spokes:'.$snapshotId;
    }

    private function mapStatus(Spoke $spoke, CarbonInterface $staleBefore): SpokeMapStatus
    {
        if ($spoke->last_polled_at === null || $spoke->last_polled_at->lt($staleBefore)) {
            return SpokeMapStatus::Red;
        }

        return match ($spoke->probe_status) {
            SpokeLiveness::Green => SpokeMapStatus::Green,
            SpokeLiveness::Red => SpokeMapStatus::Red,
            SpokeLiveness::Unknown => SpokeMapStatus::Pending,
        };
    }

    private function displayName(Spoke $spoke): string
    {
        return $spoke->name ?? 'Spoke #'.$spoke->id;
    }
}
