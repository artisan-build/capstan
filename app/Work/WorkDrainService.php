<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\WorkItem;
use App\Support\BoundCredentialSpokeResolver;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class WorkDrainService
{
    public function __construct(
        private BoundCredentialSpokeResolver $spokes,
        private WorkClaimService $claims,
        private WorkItemStateService $states,
    ) {}

    /**
     * @param  non-empty-list<string>  $roles
     * @param  list<string>  $capabilities
     * @return list<WorkItem>
     */
    public function claim(
        int $teamId,
        string $actorId,
        string $credentialId,
        int $capacity,
        array $roles,
        array $capabilities,
        CarbonImmutable $now,
    ): array {
        $spoke = DB::transaction(function () use ($actorId, $credentialId, $now): Spoke {
            return $this->spokes->resolve($actorId, $credentialId, $now);
        });

        $items = $this->claims->claim(
            $teamId,
            $actorId,
            $spoke->id,
            $roles,
            $capabilities,
            $capacity,
            $now,
            $this->leaseSeconds(),
        );

        DB::transaction(function () use ($spoke, $now): void {
            $locked = Spoke::query()->whereKey($spoke->id)->lockForUpdate()->firstOrFail();
            $this->spokes->heartbeat($locked, $now);
        });

        return $items;
    }

    public function transition(
        int $teamId,
        string $actorId,
        string $credentialId,
        string $itemId,
        int $fence,
        string $target,
        CarbonImmutable $now,
    ): WorkItem {
        return DB::transaction(function () use ($teamId, $actorId, $credentialId, $itemId, $fence, $target, $now): WorkItem {
            $spoke = $this->spokes->resolve($actorId, $credentialId, $now);
            $item = $this->scopedItem($teamId, $itemId);
            $this->assertOwnedLiveClaim($item, $actorId, $spoke->id, $fence, $now);

            try {
                $transitioned = $target === 'released'
                    ? $this->states->release($item, 'spoke', $actorId, $now)
                    : $this->states->transition($item, WorkItemState::from($target), 'spoke', $actorId, $now);
            } catch (DomainException $exception) {
                throw new ApiErrorException(422, 'illegal_transition', $exception->getMessage());
            }

            $this->spokes->heartbeat($spoke, $now);

            return $transitioned;
        });
    }

    /**
     * @param  list<array{item_id: string, fence: int}>  $requests
     * @return list<array{item_id: string, status: 'renewed'|'lost', item?: WorkItem}>
     */
    public function renew(
        int $teamId,
        string $actorId,
        string $credentialId,
        array $requests,
        CarbonImmutable $now,
    ): array {
        return DB::transaction(function () use ($teamId, $actorId, $credentialId, $requests, $now): array {
            $spoke = $this->spokes->resolve($actorId, $credentialId, $now);
            $results = [];

            foreach ($requests as $request) {
                $item = $this->scopedItem($teamId, $request['item_id']);

                if (! $this->ownsLiveClaim($item, $actorId, $spoke->id, $request['fence'], $now)) {
                    $results[] = ['item_id' => $request['item_id'], 'status' => 'lost'];

                    continue;
                }

                $item->lease_until = $now->addSeconds($this->leaseSeconds());
                $item->save();
                $results[] = ['item_id' => $request['item_id'], 'status' => 'renewed', 'item' => $item];
            }

            $this->spokes->heartbeat($spoke, $now);

            return $results;
        });
    }

    private function scopedItem(int $teamId, string $itemId): ?WorkItem
    {
        return WorkItem::query()
            ->whereKey($itemId)
            ->whereHas('workTask', fn ($query) => $query->where('team_id', $teamId))
            ->with('workTask')
            ->lockForUpdate()
            ->first();
    }

    private function assertOwnedLiveClaim(
        ?WorkItem $item,
        string $actorId,
        int $spokeId,
        int $fence,
        CarbonImmutable $now,
    ): void {
        if (! $this->ownsLiveClaim($item, $actorId, $spokeId, $fence, $now)) {
            throw new ApiErrorException(409, 'claim_lost', 'The work item claim is no longer held.');
        }
    }

    private function ownsLiveClaim(
        ?WorkItem $item,
        string $actorId,
        int $spokeId,
        int $fence,
        CarbonImmutable $now,
    ): bool {
        return $item !== null
            && in_array($item->state, [WorkItemState::Claimed, WorkItemState::Delivered, WorkItemState::Routed], true)
            && $item->fence === $fence
            && $item->claimant_actor === $actorId
            && $item->claimant_spoke_id === $spokeId
            && $item->lease_until !== null
            && $item->lease_until->isAfter($now)
            && $item->workTask->cancelled_at === null;
    }

    private function leaseSeconds(): int
    {
        return max(1, (int) config('capstan.work.lease_seconds', 300));
    }
}
