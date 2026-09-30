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

        $spokes = Spoke::query()
            ->where('actor_id', $owner->actorId)
            ->with(['inboxes' => fn ($query) => $query->where('actor_id', $owner->actorId)->orderBy('local_part')])
            ->get()
            ->sort(function (Spoke $first, Spoke $second) use ($staleBefore): int {
                return $this->compareSortKeys(
                    $this->sortKey($first, $staleBefore),
                    $this->sortKey($second, $staleBefore),
                );
            })
            ->values();

        if ($after !== null) {
            $afterKey = $after['after'] ?? null;

            if (! is_array($afterKey)
                || ! array_is_list($afterKey)
                || count($afterKey) !== 3
                || ! is_int($afterKey[0] ?? null)
                || ! is_string($afterKey[1] ?? null)
                || ! is_int($afterKey[2] ?? null)) {
                throw new JsonRpcException('The cursor is invalid for this request.', -32602);
            }

            $spokes = $spokes->filter(
                fn (Spoke $spoke): bool => $this->compareSortKeys($this->sortKey($spoke, $staleBefore), $afterKey) > 0,
            )->values();
        }

        $items = [];
        $consumed = [];

        foreach ($spokes->take($limit + 1) as $spoke) {
            if (count($items) >= $limit) {
                break;
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
                break;
            }

            $items[] = $item;
            $consumed[] = $spoke;
        }

        $hasMore = count($consumed) < $spokes->count();
        $last = $consumed[array_key_last($consumed)] ?? null;
        $nextCursor = $hasMore && $last instanceof Spoke
            ? $cursors->encode([
                'actor' => $actorBinding,
                'query' => $queryBinding,
                'stale_before' => $staleBefore->toISOString(),
                'after' => $this->sortKey($last, $staleBefore),
            ])
            : null;

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
     * @param array{int, string, int} $left
     * @param array{int, string, int} $right
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
