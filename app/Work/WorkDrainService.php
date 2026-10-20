<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Http\ApiErrorException;
use App\Machine\MachineWorkRuntime;
use App\Models\Spoke;
use App\Models\WorkItem;
use App\Support\BoundCredentialSpokeResolver;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Throwable;

final readonly class WorkDrainService
{
    private const int SQLITE_LOCK_ATTEMPTS = 50;

    public function __construct(
        private BoundCredentialSpokeResolver $spokes,
        private WorkClaimService $claims,
        private WorkItemStateService $states,
        private MachineWorkRuntime $runtime,
    ) {}

    /**
     * @param  non-empty-list<string>  $roles
     * @param  list<string>  $capabilities
     * @param  array<string, string>  $roleProviders
     * @return list<WorkItem>
     */
    public function claim(
        int $teamId,
        string $actorId,
        string $credentialId,
        int $capacity,
        array $roles,
        array $capabilities,
        array $roleProviders,
        int $reportedClaimedCount,
        CarbonImmutable $now,
    ): array {
        return DB::transaction(function () use ($teamId, $actorId, $credentialId, $capacity, $roles, $capabilities, $roleProviders, $reportedClaimedCount, $now): array {
            $presence = $this->runtime->record(
                $teamId,
                $actorId,
                $credentialId,
                $roles,
                $capabilities,
                $roleProviders,
                $reportedClaimedCount,
                $now,
            );
            if ($presence === null) {
                return [];
            }
            $spoke = Spoke::query()->findOrFail($presence->spoke_id);
            $items = $this->claims->claim(
                $teamId,
                $actorId,
                $spoke->id,
                $roles,
                $capabilities,
                $capacity,
                $now,
                $this->leaseSeconds(),
                $roleProviders,
                true,
            );
            $this->runtime->refreshCount($presence);
            $this->spokes->heartbeat($spoke, $now);

            return $items;
        });
    }

    public function transition(
        int $teamId,
        string $actorId,
        string $credentialId,
        string $itemId,
        int $fence,
        string $target,
        CarbonImmutable $now,
        ?Closure $afterIdentified = null,
    ): WorkItem {
        return $this->retrySqliteBusy(
            fn (): WorkItem => DB::transaction(function () use ($teamId, $actorId, $credentialId, $itemId, $fence, $target, $now, $afterIdentified): WorkItem {
                $spoke = $this->spokes->resolve($actorId, $credentialId, $now);
                $item = $this->scopedItem($teamId, $itemId);
                $this->assertOwnedLiveClaim($item, $actorId, $spoke->id, $fence, $now);
                $afterIdentified?->__invoke($item);

                try {
                    $transitioned = $target === 'released'
                        ? $this->states->releaseClaim($item, $teamId, $spoke->id, $actorId, $fence, $now)
                        : $this->states->transitionClaim($item, WorkItemState::from($target), $teamId, $spoke->id, $actorId, $fence, $now);
                } catch (DomainException $exception) {
                    throw new ApiErrorException(422, 'illegal_transition', $exception->getMessage());
                }

                if ($transitioned === null) {
                    throw $this->claimLost();
                }

                $this->spokes->heartbeat($spoke, $now);

                return $transitioned;
            }),
        );
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
        ?Closure $afterIdentified = null,
    ): array {
        return $this->retrySqliteBusy(
            fn (): array => DB::transaction(function () use ($teamId, $actorId, $credentialId, $requests, $now, $afterIdentified): array {
                $spoke = $this->spokes->resolve($actorId, $credentialId, $now);
                $results = [];

                foreach ($requests as $request) {
                    $item = $this->scopedItem($teamId, $request['item_id']);

                    if (! $this->ownsLiveClaim($item, $actorId, $spoke->id, $request['fence'], $now)) {
                        $results[] = ['item_id' => $request['item_id'], 'status' => 'lost'];

                        continue;
                    }

                    $afterIdentified?->__invoke($item);
                    $renewed = $this->states->renewClaim(
                        $item,
                        $teamId,
                        $spoke->id,
                        $actorId,
                        $request['fence'],
                        $now,
                        $now->addSeconds($this->leaseSeconds()),
                    );
                    $results[] = $renewed === null
                        ? ['item_id' => $request['item_id'], 'status' => 'lost']
                        : ['item_id' => $request['item_id'], 'status' => 'renewed', 'item' => $renewed];
                }

                $this->spokes->heartbeat($spoke, $now);

                return $results;
            }),
        );
    }

    private function scopedItem(int $teamId, string $itemId): ?WorkItem
    {
        return WorkItem::query()
            ->whereKey($itemId)
            ->whereHas('workTask', fn ($query) => $query->where('team_id', $teamId))
            ->with('workTask')
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
            throw $this->claimLost();
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

    private function claimLost(): ApiErrorException
    {
        return new ApiErrorException(409, 'claim_lost', 'The work item claim is no longer held.');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function retrySqliteBusy(Closure $operation): mixed
    {
        $attempt = 0;

        beginning:
        try {
            return $operation();
        } catch (Throwable $exception) {
            $attempt++;

            if ($attempt < self::SQLITE_LOCK_ATTEMPTS && $this->isSqliteLock($exception)) {
                Sleep::usleep(random_int(1_000, 20_000));
                goto beginning;
            }

            throw $exception;
        }
    }

    private function isSqliteLock(Throwable $exception): bool
    {
        return DB::getDriverName() === 'sqlite'
            && str_contains(strtolower($exception->getMessage()), 'database is locked');
    }
}
