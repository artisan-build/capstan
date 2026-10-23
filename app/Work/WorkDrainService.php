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
        private WorkDirectiveService $directives,
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
        return $this->tick(
            $teamId, $actorId, $credentialId, $capacity, $roles, $capabilities,
            $roleProviders, $reportedClaimedCount, $now,
        )['items'];
    }

    /**
     * @param  non-empty-list<string>  $roles
     * @param  list<string>  $capabilities
     * @param  array<string, string>  $roleProviders
     * @param  list<array<string, mixed>>  $heldItems
     * @return array{items: list<WorkItem>, directives: list<array<string, mixed>>}
     */
    public function tick(
        int $teamId,
        string $actorId,
        string $credentialId,
        int $capacity,
        array $roles,
        array $capabilities,
        array $roleProviders,
        int $reportedClaimedCount,
        CarbonImmutable $now,
        ?int $orchestratorCapacity = null,
        array $heldItems = [],
    ): array {
        $tick = DB::transaction(function () use ($teamId, $actorId, $credentialId, $capacity, $roles, $capabilities, $roleProviders, $reportedClaimedCount, $now, $orchestratorCapacity): array {
            $presence = $this->runtime->record(
                $teamId,
                $actorId,
                $credentialId,
                $roles,
                $capabilities,
                $roleProviders,
                $capacity,
                $reportedClaimedCount,
                $now,
                $orchestratorCapacity === null,
            );
            if ($presence === null) {
                return ['items' => [], 'spoke_id' => null];
            }
            $spoke = Spoke::query()->findOrFail($presence->spoke_id);
            if ($orchestratorCapacity === null) {
                $items = $this->claims->claim(
                    $teamId, $actorId, $spoke->id, $roles, $capabilities, $capacity, $now,
                    $this->leaseSeconds(), $roleProviders, true,
                    combinedCapacity: true,
                );
            } else {
                $leafRoles = array_values(array_diff($roles, [WorkItem::ROLE_ORCHESTRATOR, WorkItem::ROLE_HUMAN]));
                $items = $leafRoles === [] ? [] : $this->claims->claim(
                    $teamId, $actorId, $spoke->id, $leafRoles, $capabilities, $capacity, $now,
                    $this->leaseSeconds(), array_intersect_key($roleProviders, array_flip($leafRoles)), true,
                );
                if (in_array(WorkItem::ROLE_ORCHESTRATOR, $roles, true)) {
                    $available = max(0, $orchestratorCapacity - $this->runtime->authoritativeOrchestratorCount($spoke->id));
                    if ($available > 0) {
                        $items = [...$items, ...$this->claims->claim(
                            $teamId, $actorId, $spoke->id, [WorkItem::ROLE_ORCHESTRATOR], $capabilities, $available, $now,
                            $this->leaseSeconds(), array_intersect_key($roleProviders, [WorkItem::ROLE_ORCHESTRATOR => true]), true,
                            ignoreCapacityCeiling: true,
                        )];
                    }
                }
            }
            $this->runtime->refreshCount($presence, $orchestratorCapacity === null);
            $this->spokes->heartbeat($spoke, $now);

            return ['items' => $items, 'spoke_id' => $spoke->id];
        });

        return [
            'items' => $tick['items'],
            'directives' => $tick['spoke_id'] === null
                ? []
                : $this->directives->poll($teamId, $actorId, $tick['spoke_id'], $heldItems, $now),
        ];
    }

    public function transition(
        int $teamId,
        string $actorId,
        string $credentialId,
        string $itemId,
        int $fence,
        string $target,
        CarbonImmutable $now,
        ?string $launchNonce = null,
        ?string $sessionRef = null,
        ?Closure $afterIdentified = null,
    ): WorkItem {
        return $this->retrySqliteBusy(
            fn (): WorkItem => DB::transaction(function () use ($teamId, $actorId, $credentialId, $itemId, $fence, $target, $now, $launchNonce, $sessionRef, $afterIdentified): WorkItem {
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
                if ($target === WorkItemState::Delivered->value && $launchNonce !== null && $sessionRef !== null) {
                    if ($item->launch_nonce === null || ! hash_equals($item->launch_nonce, $launchNonce)) {
                        throw new ApiErrorException(409, 'launch_nonce_mismatch', 'The launch nonce does not match this claim.');
                    }
                    $transitioned->forceFill([
                        'handed_off_at' => $now,
                        'handed_off_session_ref' => $sessionRef,
                        'launch_nonce' => $launchNonce,
                        'first_fetched_at' => null,
                        'agent_model' => null,
                        'fetched_brief_revision_id' => null,
                        'fetched_page_version_id' => null,
                    ])->save();
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
