<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class WorkItemStateService
{
    /** @var array<string, list<WorkItemState>> */
    private const array NORMAL_TRANSITIONS = [
        WorkItemState::Queued->value => [WorkItemState::Claimed],
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

            if ($locked->state !== WorkItemState::Queued) {
                throw new DomainException("Work item cannot transition from {$locked->state->value} to claimed.");
            }

            $occurredAt = $at ?? CarbonImmutable::now();
            $locked->claimant_spoke_id = $claimantSpokeId;
            $locked->claimant_actor = $claimantActor;
            $locked->lease_until = $leaseUntil;
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

            if (! in_array($from, [WorkItemState::Claimed, WorkItemState::Delivered, WorkItemState::Routed], true)) {
                throw new DomainException("Work item cannot be requeued from {$from->value}.");
            }

            if ($requireExpiredLease && ($locked->lease_until === null || $locked->lease_until->isAfter($at))) {
                throw new DomainException('Work item lease has not expired.');
            }

            $locked->attempts++;
            $locked->claimant_spoke_id = null;
            $locked->claimant_actor = null;
            $locked->lease_until = null;
            $locked->claimed_at = null;
            $locked->stalled_at = null;

            return $this->persist(
                $locked,
                $from,
                WorkItemState::Queued,
                $actorKind,
                $actorId,
                $at,
            );
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

    private function lock(WorkItem $item): WorkItem
    {
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
        $item->state = $to;
        $item->save();

        $item->transitions()->create([
            'from_state' => $from,
            'to_state' => $to,
            'actor_kind' => $actorKind,
            'actor_id' => $actorId,
            'fence' => $item->fence,
            'occurred_at' => $at,
        ]);

        return $item;
    }
}
