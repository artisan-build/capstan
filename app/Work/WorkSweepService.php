<?php

namespace App\Work;

use App\Enums\WorkExpirationCause;
use App\Enums\WorkItemState;
use App\Models\Spoke;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Models\WorkTaskExpiration;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class WorkSweepService
{
    public function __construct(
        private WorkWorkerService $workers,
        private WorkNotifier $notifier,
        private WorkTaskGate $gate,
        private WorkItemStateService $states,
        private WorkDependencyService $dependencies,
    ) {}

    public function liveness(CarbonImmutable $now, ?Closure $afterIdentified = null): void
    {
        resolve(WorkPreemptionService::class)->sweep($now);
        foreach ($this->workers->recoveryQuery()->with('workTask')->get() as $item) {
            $afterIdentified?->__invoke($item);
            DB::transaction(function () use ($item, $now): void {
                resolve(WorkOrchestrationLock::class)->tasks($item->work_task_id);
                // Inspect the timebox before requeuing an expired lease so a
                // deadline and expiry in the same minute still records a stall.
                if ($item->claimed_at !== null && $item->claimed_at->addMinutes($item->workTask->timebox_minutes)->lte($now)) {
                    // Serialize with milestone writers before reading milestones.
                    // A separate statement gets a fresh PostgreSQL snapshot after
                    // any writer we waited for has committed its milestone.
                    if ($this->sameClaim($item)->update(['updated_at' => DB::raw('work_items.updated_at')]) !== 1) {
                        return;
                    }
                    $deadline = $item->claimed_at->addMinutes($item->workTask->timebox_minutes);
                    $updated = $this->sameClaim($item)->whereNull('stalled_at')
                        ->whereDoesntHave('milestones', fn ($query) => $query->where('fence', $item->fence)->where('created_at', '<=', $deadline))
                        ->update(['stalled_at' => $now]);
                    if ($updated === 1) {
                        resolve(WorkEventProjector::class)->stalled($item->fresh());
                        $this->notifier->stalled($item->fresh());
                    }
                }

                if ($item->lease_until !== null && $item->lease_until->lte($now)) {
                    $updated = $this->sameClaim($item)->where('lease_until', '<=', $now)->update([
                        'state' => 'queued', 'attempts' => DB::raw('attempts + 1'),
                        ...WorkPreemptionService::cleared(),
                        'claimant_spoke_id' => null, 'claimant_actor' => null,
                        'claimed_at' => null, 'lease_until' => null, 'stalled_at' => null,
                        'updated_at' => $now,
                    ]);
                    if ($updated === 1) {
                        $this->audit($item, WorkItemState::Queued, $now, 'lease-expiry');
                        resolve(WorkEventProjector::class)->expired($item->fresh());
                    }
                }
            }, 5);
        }

        $cutoff = $now->subSeconds(max(1, (int) config('capstan.work.missed_heartbeat_seconds', 180)));
        $liveSpokes = $this->workers->recoveryQuery()->where('lease_until', '>', $now)->select('claimant_spoke_id');
        foreach (Spoke::query()->whereIn('id', $liveSpokes)->get() as $spoke) {
            DB::transaction(function () use ($spoke, $cutoff, $now): void {
                $updated = Spoke::query()->whereKey($spoke->id)->whereNull('work_heartbeat_notified_at')
                    ->where(fn ($query) => $query->where('last_polled_at', '<=', $cutoff)->orWhereNull('last_polled_at'))
                    ->whereIn('id', $this->workers->recoveryQuery()->where('lease_until', '>', $now)->select('claimant_spoke_id'))
                    ->update(['work_heartbeat_notified_at' => $now]);
                if ($updated === 1) {
                    $this->notifier->missedHeartbeat($spoke->fresh());
                }
            }, 5);
        }
    }

    public function failover(CarbonImmutable $now, ?Closure $afterIdentified = null): void
    {
        $cutoff = $now->subSeconds(max(1, (int) config('capstan.work.failover_after_seconds', 300)));
        $candidates = WorkItem::query()->where('state', 'queued')
            ->whereNotIn('role', [WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR])
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('workTask', fn ($query) => $query->whereNull('cancelled_at')->whereNotNull('device_spoke_id')->doesntHave('children'))
            ->with('workTask')->get();

        foreach ($candidates as $item) {
            $afterIdentified?->__invoke($item);
            DB::transaction(function () use ($item, $now, $cutoff): void {
                // Take a guarded write on the queued item before touching the
                // task pin. A winning claimant must not lose its task pin.
                $queued = WorkItem::query()->whereKey($item->id)->where('state', 'queued')
                    ->where('fence', $item->fence)->where('updated_at', '<=', $cutoff)
                    ->whereHas('workTask', fn ($query) => $query->whereNull('cancelled_at')->where('device_spoke_id', $item->workTask->device_spoke_id));
                if ($item->workTask->failover) {
                    if ((clone $queued)->update(['updated_at' => DB::raw('work_items.updated_at')]) !== 1) {
                        return;
                    }
                    $updated = WorkTask::query()->whereKey($item->work_task_id)
                        ->where('device_spoke_id', $item->workTask->device_spoke_id)->where('failover', true)->whereNull('cancelled_at')
                        ->update(['device_spoke_id' => null, 'updated_at' => $now]);
                    if ($updated === 1) {
                        WorkItem::query()->where('work_task_id', $item->work_task_id)->where('state', 'queued')
                            ->update(['waiting_on_host_at' => null, 'waiting_on_host_notified_at' => null]);
                        $this->audit($item, WorkItemState::Queued, $now, 'device-failover');
                    }
                } else {
                    $updated = $queued->whereNull('waiting_on_host_notified_at')
                        ->whereHas('workTask', fn ($query) => $query->where('failover', false))
                        ->update(['waiting_on_host_at' => $now, 'waiting_on_host_notified_at' => $now]);
                    if ($updated === 1) {
                        $this->notifier->waitingOnHost($item->fresh());
                    }
                }
            }, 5);
        }
    }

    public function expiration(CarbonImmutable $now, ?Closure $afterIdentified = null): void
    {
        $candidates = WorkTask::query()
            ->whereNull('cancelled_at')
            ->where(fn ($query) => $query->whereNotNull('expires_at')->orWhereNotNull('parent_task_id'))
            ->where(fn ($query) => $query->doesntHave('expiration')->orWhereHas('items', fn ($query) => $query->where('state', WorkItemState::Queued->value)))
            ->orderBy('id')
            ->get();

        foreach ($candidates as $candidate) {
            $expiry = $this->gate->effectiveExpiry($candidate);
            if ($expiry === null || $expiry['at']->gt($now)) {
                continue;
            }

            $afterIdentified?->__invoke($candidate);
            DB::transaction(function () use ($candidate, $now): void {
                resolve(WorkOrchestrationLock::class)->tasks($candidate->id);
                $task = WorkTask::query()->whereKey($candidate->id)->firstOrFail();
                $expiry = $this->gate->effectiveExpiry($task);
                if ($expiry === null || $expiry['at']->gt($now)) {
                    return;
                }
                if ($this->gate->successful($task)) {
                    return;
                }

                $cause = $this->gate->dependenciesSuccessful($task)
                    ? WorkExpirationCause::Unclaimed
                    : WorkExpirationCause::DependencyUnmet;

                foreach ($task->items()->where('state', WorkItemState::Queued->value)->orderBy('id')->get() as $item) {
                    $this->states->transition($item, WorkItemState::Expired, 'system', 'task-expiry', $now);
                }

                WorkTaskExpiration::query()->firstOrCreate(
                    ['work_task_id' => $task->id],
                    [
                        'owner_actor' => $task->owner_actor,
                        'priority' => $task->priority,
                        'waited_seconds' => max(0, (int) $task->created_at->diffInSeconds($now, false)),
                        'provenance' => $expiry['provenance'],
                        'cause' => $cause,
                        'expired_at' => $now,
                    ],
                );
                $this->dependencies->signalFailed($task);
            }, 5);
        }
    }

    /** @return Builder<WorkItem> */
    private function sameClaim(WorkItem $item): Builder
    {
        return $this->workers->recoveryQuery()->whereKey($item->id)->where('fence', $item->fence)->where('state', $item->state->value)
            ->where('claimed_at', $item->claimed_at);
    }

    private function audit(WorkItem $item, WorkItemState $to, CarbonImmutable $now, string $reason): void
    {
        $item->transitions()->create([
            'from_state' => $item->state, 'to_state' => $to, 'fence' => $item->fence,
            'actor_kind' => 'system', 'actor_id' => $reason, 'occurred_at' => $now,
        ]);
    }
}
