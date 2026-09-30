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
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

#[Name('postmaster_spokes')]
#[Description('Lists the authenticated actor owned Postmaster spokes and currently routed inboxes.')]
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
            'status' => $schema->string()->enum(SpokeMapStatus::class),
        ];
    }

    public function handle(Request $request, OwnerSubjectResolver $owners, SignedCursor $cursors): Response|ResponseFactory
    {
        $this->requireExactArguments($request, ['limit', 'cursor', 'status']);

        if (! Feature::active(Postmaster::class)) {
            return Response::error('Postmaster is unavailable.');
        }

        $validated = $request->validate([
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'required', 'string', 'max:2048'],
            'status' => ['sometimes', 'required', Rule::enum(SpokeMapStatus::class)],
        ]);
        $owner = $owners->resolve();
        $limit = (int) ($validated['limit'] ?? 10);
        $status = isset($validated['status']) ? SpokeMapStatus::from($validated['status']) : null;
        $actorBinding = $owner->type.':'.$owner->ref;
        $staleAfter = max(60, (int) config('capstan.postmaster.map.stale_after_seconds', 300));
        $queryBinding = hash('sha256', 'postmaster_spokes:v2:'.$staleAfter.':'.$limit.':'.($status === null ? '*' : $status->value));
        $after = isset($validated['cursor'])
            ? $cursors->decode($validated['cursor'], $actorBinding, $queryBinding)
            : null;
        $lastId = $after['last_id'] ?? null;

        if ($after !== null && (! is_int($lastId) || $lastId < 1)) {
            throw new JsonRpcException('The cursor is invalid for this request.', -32602);
        }

        $staleBefore = now()->subSeconds($staleAfter);
        $query = Spoke::query()
            ->where('actor_id', $owner->actorId)
            ->when($lastId !== null, fn ($query) => $query->where('id', '>', $lastId));

        if ($status === SpokeMapStatus::Red) {
            $query->where(function ($query) use ($staleBefore): void {
                $query->whereNull('last_polled_at')
                    ->orWhere('last_polled_at', '<', $staleBefore)
                    ->orWhere('probe_status', SpokeLiveness::Red->value);
            });
        } elseif ($status !== null) {
            $query->whereNotNull('last_polled_at')
                ->where('last_polled_at', '>=', $staleBefore)
                ->where(
                    'probe_status',
                    $status === SpokeMapStatus::Green ? SpokeLiveness::Green->value : SpokeLiveness::Unknown->value,
                );
        }

        $candidates = $query
            ->with(['inboxes' => fn ($query) => $query->where('actor_id', $owner->actorId)->orderBy('local_part')])
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $items = [];
        $consumed = [];

        foreach ($candidates as $spoke) {
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

        $hasMore = count($consumed) < $candidates->count() || $candidates->count() > $limit;
        $last = $consumed[array_key_last($consumed)] ?? null;
        $nextCursor = $hasMore && $last instanceof Spoke
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
            'stale_after_seconds' => $staleAfter,
        ]);
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
