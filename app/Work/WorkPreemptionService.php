<?php

namespace App\Work;

use App\Enums\WorkPriority;
use App\Models\WorkItem;
use App\Models\WorkTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class WorkPreemptionService
{
    public function __construct(private WorkOrchestrationLock $locks, private WorkNotifier $notifier) {}

    /** @return Builder<WorkItem> */
    private function live(int $machineId, CarbonImmutable $now): Builder
    {
        return WorkItem::query()->where('claimant_spoke_id', $machineId)
            ->whereIn('state', ['claimed', 'delivered', 'routed'])
            ->where('lease_until', '>', $now);
    }

    /** @param list<string> $excludedTasks */
    public function victim(int $machineId, CarbonImmutable $now, array $excludedTasks = []): ?WorkItem
    {
        // One outstanding release per full machine; further ticks wait for real capacity.
        if ($this->live($machineId, $now)->whereNotNull('release_requested_at')->exists()) {
            return null;
        }

        return $this->live($machineId, $now)
            ->whereNotIn('work_task_id', $excludedTasks)
            ->whereNotIn('role', [WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR])
            ->whereNull('release_requested_at')
            ->whereHas('workTask', fn ($query) => $query->whereIn('priority', ['P1', 'P2', 'P3'])->whereNull('cancelled_at'))
            ->orderByDesc(WorkTask::query()->select('priority')->whereColumn('work_tasks.id', 'work_items.work_task_id'))
            ->orderByRaw("CASE state WHEN 'claimed' THEN 0 WHEN 'delivered' THEN 1 ELSE 2 END")
            ->orderBy('id')->first();
    }

    /** Caller holds the machine lock and the eligible P0 task's dependency locks. */
    public function request(WorkItem $opportunity, int $machineId, CarbonImmutable $now): bool
    {
        if ($opportunity->state->value !== 'queued'
            || in_array($opportunity->role, [WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR], true)
            || $opportunity->workTask->priority !== WorkPriority::P0
            || WorkItem::query()->where('release_for_item_id', $opportunity->id)->exists()) {
            return false;
        }
        $victim = $this->victim($machineId, $now);
        if ($victim === null) {
            return false;
        }
        $this->locks->tasks($victim->work_task_id);
        $updated = $this->live($machineId, $now)->whereKey($victim->id)
            ->where('fence', $victim->fence)->whereNull('release_requested_at')
            ->whereHas('workTask', fn ($query) => $query->whereIn('priority', ['P1', 'P2', 'P3'])->whereNull('cancelled_at'))
            ->update([
                'release_requested_at' => $now,
                'release_deadline' => $now->addSeconds(max(1, min(3600, (int) config('capstan.work.preemption_grace_seconds', 60)))),
                'release_for_item_id' => $opportunity->id,
            ]);
        if ($updated !== 1) {
            return false;
        }
        $this->notifier->preempted($victim->refresh());

        return true;
    }

    public function sweep(CarbonImmutable $now): void
    {
        foreach (WorkItem::query()->whereNotNull('release_requested_at')->where('release_deadline', '<=', $now)->get() as $candidate) {
            DB::transaction(function () use ($candidate, $now): void {
                $this->locks->tasks($candidate->work_task_id);
                $updated = WorkItem::query()->whereKey($candidate->id)->where('fence', $candidate->fence)
                    ->whereIn('state', ['claimed', 'delivered', 'routed'])
                    ->whereNotNull('release_requested_at')->where('release_deadline', '<=', $now)
                    ->update([
                        'state' => 'queued', 'fence' => DB::raw('fence + 1'), 'attempts' => DB::raw('attempts + 1'),
                        'claimant_actor' => null, 'claimant_spoke_id' => null, 'lease_until' => null,
                        'work_deadline_at' => null, 'first_deadline_missed_at' => null,
                        'claimed_at' => null, 'stalled_at' => null,
                        ...self::cleared(), 'updated_at' => $now,
                    ]);
                if ($updated === 1) {
                    $candidate->transitions()->create([
                        'from_state' => $candidate->state, 'to_state' => 'queued', 'fence' => $candidate->fence + 1,
                        'actor_kind' => 'system', 'actor_id' => 'preemption-timeout', 'occurred_at' => $now,
                    ]);
                }
            }, 5);
        }
    }

    /** @return array{release_requested_at: null, release_deadline: null, release_for_item_id: null} */
    public static function cleared(): array
    {
        return ['release_requested_at' => null, 'release_deadline' => null, 'release_for_item_id' => null];
    }

    /** @return array<string, mixed> */
    public static function representation(WorkItem $item): array
    {
        return $item->release_requested_at === null ? [] : [
            'release_requested' => [
                'requested_at' => $item->release_requested_at->toISOString(),
                ...($item->release_deadline === null ? [] : ['deadline' => $item->release_deadline->toISOString()]),
                'reason' => 'P0_preemption',
            ],
        ];
    }
}
