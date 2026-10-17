<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Features\Work;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

final readonly class WorkSeatService
{
    public function __construct(private WorkOrchestrationLock $locks, private WorkNotifier $notifier) {}

    public function take(WorkTask $parent, string $actor, int $spoke, int $expectedFence, ?string $sessionRef, bool $interactive, CarbonImmutable $now, int $leaseSeconds = 300): ?WorkOrchestratorSeat
    {
        if (! Feature::active(Work::class)) {
            return null;
        }

        return DB::transaction(function () use ($parent, $actor, $spoke, $expectedFence, $sessionRef, $interactive, $now, $leaseSeconds): WorkOrchestratorSeat {
            $this->locks->tasks($parent->id);
            $seat = $this->locks->seat($parent);
            if ($seat->fence !== $expectedFence || $parent->fresh()->cancelled_at !== null) {
                throw new DomainException('Seat fence lost.');
            }
            $same = $seat->holder_actor === $actor && $seat->holder_spoke_id === $spoke;
            if (! $same && $seat->holder_actor !== null && (($seat->lease_until?->isAfter($now) ?? false) || ! $seat->failover)) {
                throw new DomainException('Seat is held by another host.');
            }
            if (! $same) {
                $this->recover($seat, $now);
                $seat->fence++;
            }
            $seat->fill([
                'holder_actor' => $actor, 'holder_spoke_id' => $spoke, 'session_ref' => $sessionRef,
                'interactive' => $interactive, 'lease_until' => $now->addSeconds(max(1, $leaseSeconds)),
                'waiting_at' => null, 'notified_at' => null,
            ])->save();

            return $seat;
        }, 5);
    }

    public function renew(WorkTask $parent, string $actor, int $spoke, int $fence, ?string $sessionRef, CarbonImmutable $now, int $leaseSeconds = 300): ?WorkOrchestratorSeat
    {
        if (! Feature::active(Work::class)) {
            return null;
        }

        return DB::transaction(function () use ($parent, $actor, $spoke, $fence, $sessionRef, $now, $leaseSeconds): WorkOrchestratorSeat {
            $this->locks->tasks($parent->id);
            $seat = $this->owned($parent, $actor, $spoke, $fence);
            $seat->fill(['session_ref' => $sessionRef, 'lease_until' => $now->addSeconds(max(1, $leaseSeconds)), 'waiting_at' => null, 'notified_at' => null])->save();

            return $seat;
        }, 5);
    }

    public function release(WorkTask $parent, string $actor, int $spoke, int $fence, CarbonImmutable $now): ?WorkOrchestratorSeat
    {
        if (! Feature::active(Work::class)) {
            return null;
        }

        return DB::transaction(function () use ($parent, $actor, $spoke, $fence, $now): WorkOrchestratorSeat {
            $this->locks->tasks($parent->id);
            $seat = $this->owned($parent, $actor, $spoke, $fence);
            $this->recover($seat, $now);
            $seat->fence++;
            $seat->fill(['holder_actor' => null, 'holder_spoke_id' => null, 'session_ref' => null, 'interactive' => false, 'lease_until' => null, 'waiting_at' => null, 'notified_at' => null])->save();

            return $seat;
        }, 5);
    }

    /** Rotate authority without discarding the holder's opaque session or durable memory. */
    public function fence(WorkTask $parent, string $actor, int $spoke, int $fence, CarbonImmutable $now): ?WorkOrchestratorSeat
    {
        if (! Feature::active(Work::class)) {
            return null;
        }

        return DB::transaction(function () use ($parent, $actor, $spoke, $fence, $now): WorkOrchestratorSeat {
            $this->locks->tasks($parent->id);
            $seat = $this->owned($parent, $actor, $spoke, $fence);
            $this->recover($seat, $now);
            $seat->fence++;
            $seat->save();

            return $seat;
        }, 5);
    }

    /** Read-only candidate hint. The locked claim rechecks it. */
    public function eligible(?WorkOrchestratorSeat $seat, string $actor, int $spoke, CarbonImmutable $now, ?int $deviceSpokeId = null): bool
    {
        if (! Feature::active(Work::class)) {
            return false;
        }
        // A device pin governs initial placement. Only an established failover
        // seat may move off it; ordinary items keep their own device predicate.
        if ($deviceSpokeId !== null && $deviceSpokeId !== $spoke
            && ! ($seat !== null && $seat->failover && $seat->fence > 0)) {
            return false;
        }
        if ($seat === null || $seat->holder_actor === null) {
            return $seat?->stopped_at === null;
        }
        if ($seat->stopped_at !== null) {
            return false;
        }
        $live = $seat->lease_until?->isAfter($now) ?? false;
        if ($live && $seat->interactive) {
            return false;
        }

        return ($seat->holder_actor === $actor && $seat->holder_spoke_id === $spoke)
            || (! $live && $seat->failover);
    }

    /** Caller holds parent lock. Also adopts a pre-engine queued orchestrator item. */
    public function forClaim(WorkTask $parent, WorkItem $item, string $actor, int $spoke, CarbonImmutable $now, int $leaseSeconds): ?WorkOrchestratorSeat
    {
        if (! Feature::active(Work::class)) {
            return null;
        }
        $seat = $this->locks->seat($parent);
        if (! $this->eligible($seat, $actor, $spoke, $now, $parent->device_spoke_id)) {
            return null;
        }
        if ($seat->active_item_id !== null && $seat->active_item_id !== $item->id) {
            $active = WorkItem::query()->find($seat->active_item_id);
            if ($active !== null && ! $active->state->isTerminal()) {
                return null;
            }
        }
        // Also guard legacy/directly queued turns that predate the slot.
        if ($parent->items()->where('role', WorkItem::ROLE_ORCHESTRATOR)->whereIn('state', ['claimed', 'delivered', 'routed'])->whereKeyNot($item->id)->exists()) {
            return null;
        }
        $same = $seat->holder_actor === $actor && $seat->holder_spoke_id === $spoke;
        if (! $same) {
            $seat->fence++;
            $seat->session_ref = null;
        }
        $seat->fill([
            'holder_actor' => $actor, 'holder_spoke_id' => $spoke,
            'interactive' => false, 'lease_until' => $now->addSeconds(max(1, $leaseSeconds)),
            'waiting_at' => null, 'notified_at' => null, 'active_item_id' => $item->id,
        ])->save();

        return $seat;
    }

    public function sweep(CarbonImmutable $now): void
    {
        if (! Feature::active(Work::class)) {
            return;
        }
        $cutoff = $now->subSeconds(max(1, (int) config('capstan.work.failover_after_seconds', 300)));
        foreach (WorkOrchestratorSeat::query()->where(fn ($query) => $query
            ->where('lease_until', '<=', $now)
            ->orWhere(fn ($query) => $query->where('failover', false)->whereNull('holder_actor')->whereNotNull('active_item_id')))->get() as $candidate) {
            DB::transaction(function () use ($candidate, $now, $cutoff): void {
                $this->locks->tasks($candidate->work_task_id);
                $seat = $candidate->fresh();
                $parent = WorkTask::query()->findOrFail($seat->work_task_id);
                if ($parent->cancelled_at !== null || $seat->stopped_at !== null
                    || ($seat->lease_until?->isAfter($now) ?? false)) {
                    return;
                }
                $queued = WorkItem::query()->whereKey($seat->active_item_id)->where('role', WorkItem::ROLE_ORCHESTRATOR)
                    ->where('state', WorkItemState::Queued)->first();
                if ($parent->device_spoke_id !== null && ! $seat->failover && $queued !== null && $queued->updated_at->isAfter($cutoff)) {
                    return;
                }
                $lapsed = $seat->holder_actor !== null && $seat->lease_until !== null;
                if (! $lapsed && ($seat->failover || $parent->device_spoke_id === null || $queued === null)) {
                    return;
                }
                if ($seat->failover) {
                    $this->recover($seat, $now);
                    $seat->fence++;
                    $seat->fill(['holder_actor' => null, 'holder_spoke_id' => null, 'session_ref' => null, 'interactive' => false, 'lease_until' => null])->save();
                } elseif ($seat->notified_at === null) {
                    if ($queued !== null) {
                        $queued->fill(['waiting_on_host_at' => $now, 'waiting_on_host_notified_at' => $now])->save();
                    }
                    $seat->fill(['waiting_at' => $now, 'notified_at' => $now])->save();
                    $this->notifier->seatWaiting($seat);
                }
            }, 5);
        }
    }

    private function owned(WorkTask $parent, string $actor, int $spoke, int $fence): WorkOrchestratorSeat
    {
        $seat = $this->locks->seat($parent);
        if ($seat->fence !== $fence || $seat->holder_actor !== $actor || $seat->holder_spoke_id !== $spoke || $parent->fresh()->cancelled_at !== null) {
            throw new DomainException('Seat fence lost.');
        }

        return $seat;
    }

    /** Invalidate the old item fence before allowing any replacement host to hold the seat. */
    private function recover(WorkOrchestratorSeat $seat, CarbonImmutable $now): void
    {
        if ($seat->active_item_id === null) {
            return;
        }
        $item = WorkItem::query()->find($seat->active_item_id);
        if ($item === null || $item->state->isTerminal() || $item->state === WorkItemState::Queued) {
            return;
        }
        $from = $item->state;
        $item->fence++;
        $item->attempts++;
        $item->fill([
            'state' => WorkItemState::Queued, 'claimant_spoke_id' => null, 'claimant_actor' => null,
            'lease_until' => null, 'claimed_at' => null, 'stalled_at' => null,
        ])->save();
        $item->transitions()->create([
            'from_state' => $from, 'to_state' => WorkItemState::Queued, 'fence' => $item->fence,
            'actor_kind' => 'system', 'actor_id' => 'seat-recovery', 'occurred_at' => $now,
        ]);
    }
}
