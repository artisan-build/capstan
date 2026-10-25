<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Models\Spoke;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WorkItemStateService
{
    /** @var array<string, list<WorkItemState>> */
    private const array NORMAL_TRANSITIONS = [
        WorkItemState::Queued->value => [WorkItemState::Expired],
        WorkItemState::Claimed->value => [WorkItemState::Delivered],
        WorkItemState::Delivered->value => [WorkItemState::Routed],
        WorkItemState::Routed->value => [WorkItemState::Acted, WorkItemState::Failed],
    ];

    public function transition(
        WorkItem $item,
        WorkItemState $to,
        string $actorKind,
        string $actorId,
        ?CarbonImmutable $at = null,
    ): WorkItem {
        return DB::transaction(function () use ($item, $to, $actorKind, $actorId, $at): WorkItem {
            $locked = $this->lock($item);
            $from = $locked->state;

            if (! $this->canTransition($from, $to)) {
                throw new DomainException("Work item cannot transition from {$from->value} to {$to->value}.");
            }

            return $this->persist($locked, $from, $to, $actorKind, $actorId, $at ?? CarbonImmutable::now());
        });
    }

    public function claim(
        WorkItem $item,
        int $claimantSpokeId,
        string $claimantActor,
        CarbonImmutable $leaseUntil,
        string $actorKind,
        string $actorId,
        ?CarbonImmutable $at = null,
    ): WorkItem {
        return DB::transaction(function () use ($item, $claimantSpokeId, $claimantActor, $leaseUntil, $actorKind, $actorId, $at): WorkItem {
            $locked = $this->lock($item);

            if (in_array($locked->role, [WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR], true)) {
                throw new DomainException('Reserved roles require engine claim arbitration.');
            }

            if ($locked->state !== WorkItemState::Queued) {
                throw new DomainException("Work item cannot transition from {$locked->state->value} to claimed.");
            }
            if (! Spoke::query()->workEligible()->whereKey($claimantSpokeId)->where('actor_id', $claimantActor)->lockForUpdate()->exists()) {
                throw new DomainException('Work host is unavailable.');
            }

            $occurredAt = $at ?? CarbonImmutable::now();
            $locked->claimant_spoke_id = $claimantSpokeId;
            $locked->claimant_actor = $claimantActor;
            $locked->lease_until = $leaseUntil;
            $locked->work_deadline_at = null;
            $locked->first_deadline_missed_at = null;
            $locked->claimed_at = $occurredAt;
            $locked->fence++;

            return $this->persist(
                $locked,
                WorkItemState::Queued,
                WorkItemState::Claimed,
                $actorKind,
                $actorId,
                $occurredAt,
            );
        });
    }

    public function release(
        WorkItem $item,
        string $actorKind,
        string $actorId,
        ?CarbonImmutable $at = null,
    ): WorkItem {
        return $this->requeue($item, $actorKind, $actorId, $at ?? CarbonImmutable::now(), false);
    }

    public function expireLease(
        WorkItem $item,
        string $actorKind,
        string $actorId,
        ?CarbonImmutable $at = null,
    ): WorkItem {
        return $this->requeue($item, $actorKind, $actorId, $at ?? CarbonImmutable::now(), true);
    }

    public function transitionClaim(
        WorkItem $item,
        WorkItemState $to,
        int $teamId,
        int $claimantSpokeId,
        string $claimantActor,
        int $fence,
        CarbonImmutable $at,
    ): ?WorkItem {
        return DB::transaction(function () use ($item, $to, $teamId, $claimantSpokeId, $claimantActor, $fence, $at): ?WorkItem {
            resolve(WorkOrchestrationLock::class)->tasks($item->work_task_id);
            $this->assertNotTurnDone($item, $to);
            $from = $item->state;

            if (! $this->canTransition($from, $to)) {
                throw new DomainException("Work item cannot transition from {$from->value} to {$to->value}.");
            }

            $updated = $this->guardedClaimQuery(
                $item,
                [$from],
                $teamId,
                $claimantSpokeId,
                $claimantActor,
                $fence,
                $at,
            )->update([
                'state' => $to->value,
                ...($to->isTerminal() ? WorkPreemptionService::cleared() : []),
                ...($to->isTerminal() ? ['work_deadline_at' => null, 'first_deadline_missed_at' => null] : []),
                'updated_at' => $at,
            ]);

            if ($updated !== 1) {
                return null;
            }

            $transitioned = WorkItem::query()->whereKey($item->id)->firstOrFail();
            $this->appendTransition($transitioned, $from, $to, 'spoke', $claimantActor, $at);

            return $transitioned;
        });
    }

    public function releaseClaim(
        WorkItem $item,
        int $teamId,
        int $claimantSpokeId,
        string $claimantActor,
        int $fence,
        CarbonImmutable $at,
    ): ?WorkItem {
        return DB::transaction(function () use ($item, $teamId, $claimantSpokeId, $claimantActor, $fence, $at): ?WorkItem {
            resolve(WorkOrchestrationLock::class)->tasks($item->work_task_id);
            $from = $item->state;

            if (! $this->canRequeue($from)) {
                throw new DomainException("Work item cannot be requeued from {$from->value}.");
            }

            $updated = $this->guardedClaimQuery(
                $item,
                [$from],
                $teamId,
                $claimantSpokeId,
                $claimantActor,
                $fence,
                $at,
            )->update([
                'state' => WorkItemState::Queued->value,
                ...WorkPreemptionService::cleared(),
                'attempts' => DB::raw('attempts + 1'),
                'claimant_spoke_id' => null,
                'claimant_actor' => null,
                'lease_until' => null,
                'work_deadline_at' => null,
                'first_deadline_missed_at' => null,
                'claimed_at' => null,
                'stalled_at' => null,
                'updated_at' => $at,
            ]);

            if ($updated !== 1) {
                return null;
            }

            $released = WorkItem::query()->whereKey($item->id)->firstOrFail();
            $this->appendTransition($released, $from, WorkItemState::Queued, 'spoke', $claimantActor, $at);

            return $released;
        });
    }

    public function renewClaim(
        WorkItem $item,
        int $teamId,
        int $claimantSpokeId,
        string $claimantActor,
        int $fence,
        CarbonImmutable $at,
        CarbonImmutable $leaseUntil,
    ): ?WorkItem {
        return DB::transaction(function () use ($item, $teamId, $claimantSpokeId, $claimantActor, $fence, $at, $leaseUntil): ?WorkItem {
            resolve(WorkOrchestrationLock::class)->tasks($item->work_task_id);
            $updated = $this->guardedClaimQuery(
                $item,
                [WorkItemState::Claimed, WorkItemState::Delivered, WorkItemState::Routed, WorkItemState::AwaitingVerification],
                $teamId,
                $claimantSpokeId,
                $claimantActor,
                $fence,
                $at,
            )->update([
                'lease_until' => $leaseUntil,
                'updated_at' => $at,
            ]);

            if ($updated !== 1) {
                return null;
            }
            $renewed = WorkItem::query()->whereKey($item->id)->firstOrFail();
            if ($renewed->role === WorkItem::ROLE_ORCHESTRATOR && $renewed->seat_fence !== null) {
                $seat = WorkOrchestratorSeat::query()->where('work_task_id', $renewed->work_task_id)->firstOrFail();
                resolve(WorkSeatService::class)->renew(
                    $renewed->workTask, $claimantActor, $claimantSpokeId, $renewed->seat_fence,
                    $seat->session_ref, $at, max(1, (int) $at->diffInSeconds($leaseUntil)),
                );
                $renewed->session_ref = $seat->session_ref;
            }

            return $renewed;
        });
    }

    private function requeue(
        WorkItem $item,
        string $actorKind,
        string $actorId,
        CarbonImmutable $at,
        bool $requireExpiredLease,
    ): WorkItem {
        return DB::transaction(function () use ($item, $actorKind, $actorId, $at, $requireExpiredLease): WorkItem {
            $locked = $this->lock($item);
            $from = $locked->state;

            if (! $this->canRequeue($from)) {
                throw new DomainException("Work item cannot be requeued from {$from->value}.");
            }

            if ($requireExpiredLease && ($locked->lease_until === null || $locked->lease_until->isAfter($at))) {
                throw new DomainException('Work item lease has not expired.');
            }

            $locked->attempts++;
            $locked->claimant_spoke_id = null;
            $locked->claimant_actor = null;
            $locked->lease_until = null;
            $locked->work_deadline_at = null;
            $locked->first_deadline_missed_at = null;
            $locked->claimed_at = null;
            $locked->stalled_at = null;
            $locked->fill(WorkPreemptionService::cleared());

            $requeued = $this->persist(
                $locked,
                $from,
                WorkItemState::Queued,
                $actorKind,
                $actorId,
                $at,
            );
            if ($requireExpiredLease) {
                resolve(WorkEventProjector::class)->expired($requeued);
            }

            return $requeued;
        });
    }

    private function canTransition(WorkItemState $from, WorkItemState $to): bool
    {
        if ($from->isTerminal()) {
            return false;
        }

        if ($to === WorkItemState::Cancelled) {
            return true;
        }

        return in_array($to, self::NORMAL_TRANSITIONS[$from->value] ?? [], true);
    }

    private function canRequeue(WorkItemState $from): bool
    {
        return in_array($from, [WorkItemState::Claimed, WorkItemState::Delivered, WorkItemState::Routed], true);
    }

    /**
     * @param  non-empty-list<WorkItemState>  $states
     * @return Builder<WorkItem>
     */
    private function guardedClaimQuery(
        WorkItem $item,
        array $states,
        int $teamId,
        int $claimantSpokeId,
        string $claimantActor,
        int $fence,
        CarbonImmutable $at,
    ): Builder {
        return WorkItem::query()
            ->whereKey($item->id)
            ->whereIn('state', array_map(fn (WorkItemState $state): string => $state->value, $states))
            ->where('fence', $fence)
            ->where('claimant_spoke_id', $claimantSpokeId)
            ->where('claimant_actor', $claimantActor)
            ->where('lease_until', '>', $at)
            ->whereIn('claimant_spoke_id', Spoke::query()->workEligible()
                ->where('actor_id', $claimantActor)
                ->select('id'))
            ->whereHas('workTask', fn (Builder $query): Builder => $query
                ->where('team_id', $teamId)
                ->whereNull('cancelled_at'));
    }

    private function lock(WorkItem $item): WorkItem
    {
        resolve(WorkOrchestrationLock::class)->tasks($item->work_task_id);

        return WorkItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
    }

    private function persist(
        WorkItem $item,
        WorkItemState $from,
        WorkItemState $to,
        string $actorKind,
        string $actorId,
        CarbonImmutable $at,
    ): WorkItem {
        $this->assertNotTurnDone($item, $to);
        $item->state = $to;
        if ($to->isTerminal()) {
            $item->fill(WorkPreemptionService::cleared());
            $item->work_deadline_at = null;
            $item->first_deadline_missed_at = null;
        }
        $item->save();

        $this->appendTransition($item, $from, $to, $actorKind, $actorId, $at);

        return $item;
    }

    private function appendTransition(
        WorkItem $item,
        WorkItemState $from,
        WorkItemState $to,
        string $actorKind,
        string $actorId,
        CarbonImmutable $at,
    ): void {
        $item->transitions()->create([
            'from_state' => $from,
            'to_state' => $to,
            'actor_kind' => $actorKind,
            'actor_id' => $actorId,
            'fence' => $item->fence,
            'occurred_at' => $at,
        ]);
        resolve(WorkEventProjector::class)->terminal($item);
    }

    private function assertNotTurnDone(WorkItem $item, WorkItemState $to): void
    {
        if ($item->role === WorkItem::ROLE_ORCHESTRATOR && $to === WorkItemState::Acted) {
            throw new DomainException('Orchestrator turns require turn_done.');
        }
    }
}
