<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Models\Spoke;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestrationDecision;
use App\Models\WorkOrchestrationEvent;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkStateRevision;
use App\Models\WorkTask;
use App\Models\WorkWake;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WorkOrchestrationService
{
    public function __construct(private WorkOrchestrationLock $locks) {}

    /** Source writers call this inside the source transaction, after root-first locking.
     * @param  array<string, mixed>  $evidence
     */
    public function event(WorkTask $parent, string $sourceKey, string $kind, array $evidence = [], ?string $authorTaskId = null, bool $inheritAuthor = true): WorkOrchestrationEvent
    {
        return DB::transaction(function () use ($parent, $sourceKey, $kind, $evidence, $authorTaskId, $inheritAuthor): WorkOrchestrationEvent {
            $this->locks->tasks($parent->id);
            $seat = $this->locks->seat($parent);
            $existing = WorkOrchestrationEvent::query()->where('work_task_id', $parent->id)->where('source_key', $sourceKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            $author = $authorTaskId ?? ($inheritAuthor ? WorkOrchestrationAuthor::current() : null);
            if ($author !== null && ! Str::isUuid($author)) {
                throw new DomainException('Event author must be a task UUID.');
            }
            $seat->event_sequence++;
            $seat->save();
            $event = WorkOrchestrationEvent::query()->create([
                'work_task_id' => $parent->id, 'sequence' => $seat->event_sequence,
                'source_key' => $sourceKey, 'kind' => $kind,
                'evidence' => array_filter($evidence, fn ($value): bool => $value !== null),
                'author_task_id' => $author, 'wakes' => $author !== $parent->id,
            ]);
            $this->queue($parent, $seat);

            return $event;
        }, 5);
    }

    /** Caller holds the parent lock. Queued, claimed, delivered and routed all occupy the slot. */
    public function queue(WorkTask $parent, WorkOrchestratorSeat $seat): void
    {
        if ($parent->fresh()->cancelled_at !== null || $seat->stopped_at !== null) {
            return;
        }
        if ($seat->active_item_id !== null) {
            $active = WorkItem::query()->find($seat->active_item_id);
            if ($active !== null && ! $active->state->isTerminal()) {
                return;
            }
            $seat->active_item_id = null;
            $seat->save();
        }
        if (! WorkOrchestrationEvent::query()->where('work_task_id', $parent->id)->where('sequence', '>', $seat->completed_cursor)->where('wakes', true)->exists()) {
            return;
        }
        if (max($seat->turns_started, $seat->turns_completed) >= max(1, (int) config('capstan.work.orchestration.turn_budget', 20))) {
            $this->stop($parent, $seat, 'turn_budget');

            return;
        }
        $item = $parent->items()->create(['role' => WorkItem::ROLE_ORCHESTRATOR]);
        $seat->active_item_id = $item->id;
        $seat->save();
    }

    /** Count a new turn once, not each recovery claim. Also covers directly queued roles. */
    public function startTurn(WorkTask $parent, WorkOrchestratorSeat $seat, WorkItem $item): bool
    {
        if ($seat->stopped_at !== null) {
            return false;
        }
        if ($item->turn_upper_bound === null) {
            if (max($seat->turns_started, $seat->turns_completed) >= max(1, (int) config('capstan.work.orchestration.turn_budget', 20))) {
                $this->stop($parent, $seat, 'turn_budget');

                return false;
            }
            $seat->turns_started++;
            $seat->save();
        }

        return true;
    }

    /** Called after a terminal orchestrator transition, under the parent lock. */
    public function ended(WorkItem $item): void
    {
        if ($item->role !== WorkItem::ROLE_ORCHESTRATOR) {
            return;
        }
        $seat = $this->locks->seat($item->workTask);
        if ($seat->active_item_id === $item->id) {
            $seat->active_item_id = null;
            $seat->save();
            $this->queue($item->workTask, $seat);
        }
    }

    /** @return array<string, mixed> */
    public function turn(WorkItem $item): array
    {
        $seat = WorkOrchestratorSeat::query()->where('work_task_id', $item->work_task_id)->firstOrFail();
        $lower = $item->turn_cursor ?? $seat->completed_cursor;
        $upper = $item->turn_upper_bound ?? $seat->event_sequence;

        return array_filter([
            'cursor' => $lower, 'through' => $upper, 'seat_fence' => $item->seat_fence,
            'session_ref' => $seat->session_ref,
            'events' => WorkOrchestrationEvent::query()->where('work_task_id', $item->work_task_id)
                ->where('sequence', '>', $lower)->where('sequence', '<=', $upper)->orderBy('sequence')->get()
                ->map(fn (WorkOrchestrationEvent $event): array => [
                    'id' => $event->id, 'sequence' => $event->sequence, 'kind' => $event->kind, 'evidence' => $event->evidence,
                ])->all(),
        ], fn ($value): bool => $value !== null);
    }

    public function turnDone(WorkItem $item, int $itemFence, int $seatFence, string $content, CarbonImmutable $now): WorkStateRevision
    {
        return DB::transaction(function () use ($item, $itemFence, $seatFence, $content, $now): WorkStateRevision {
            [$locked, $seat] = $this->guardTurn($item, $itemFence, $seatFence, $now);
            $revision = WorkStateRevision::query()->create([
                'work_task_id' => $locked->work_task_id, 'work_item_id' => $locked->id,
                'sequence' => $seat->turns_completed + 1, 'through_cursor' => $locked->turn_upper_bound,
                'content' => $content,
            ]);
            $from = $locked->state;
            $locked->state = WorkItemState::Acted;
            $locked->save();
            $locked->transitions()->create([
                'from_state' => $from, 'to_state' => WorkItemState::Acted,
                'actor_kind' => 'orchestrator', 'actor_id' => $seat->holder_actor,
                'fence' => $itemFence, 'occurred_at' => $now,
            ]);
            $seat->completed_cursor = (int) $locked->turn_upper_bound;
            $seat->turns_completed++;
            $seat->active_item_id = null;
            $seat->save();
            resolve(WorkEventProjector::class)->terminal($locked);
            $this->queue($locked->workTask, $seat->refresh());

            return $revision;
        }, 5);
    }

    /** @return array{WorkItem, WorkOrchestratorSeat} */
    public function guardTurn(WorkItem $item, int $itemFence, int $seatFence, CarbonImmutable $now): array
    {
        $this->locks->tasks($item->work_task_id);
        $seat = $this->locks->seat($item->workTask);
        $locked = WorkItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
        $spokeAvailable = Spoke::query()->workEligible()
            ->whereKey($locked->claimant_spoke_id)
            ->where('actor_id', $locked->claimant_actor)
            ->lockForUpdate()
            ->exists();
        if ($locked->role !== WorkItem::ROLE_ORCHESTRATOR || $locked->state->isTerminal()
            || $locked->state === WorkItemState::Queued || $locked->fence !== $itemFence
            || $seat->fence !== $seatFence || $locked->seat_fence !== $seatFence
            || $seat->active_item_id !== $locked->id || $locked->turn_upper_bound === null
            || $locked->claimant_actor !== $seat->holder_actor || $locked->claimant_spoke_id !== $seat->holder_spoke_id
            || $locked->lease_until === null || $locked->lease_until->lte($now)
            || $seat->lease_until === null || $seat->lease_until->lte($now)
            || ! $spokeAvailable || $locked->workTask->cancelled_at !== null || $seat->stopped_at !== null) {
            throw new DomainException('Orchestrator item or seat fence lost.');
        }

        return [$locked, $seat];
    }

    /** Scoped provenance for later subtree surfaces; no HTTP or MCP capability is added.
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T|null
     */
    public function authored(WorkItem $turn, int $itemFence, int $seatFence, CarbonImmutable $now, Closure $write): mixed
    {
        return DB::transaction(function () use ($turn, $itemFence, $seatFence, $now, $write): mixed {
            $this->guardTurn($turn, $itemFence, $seatFence, $now);

            return WorkOrchestrationAuthor::run($turn->work_task_id, $write);
        }, 5);
    }

    /** @param array<string, mixed> $evidence */
    public function decide(WorkItem $turn, int $itemFence, int $seatFence, string $eventId, string $decision, string $rationale, array $evidence, CarbonImmutable $now): ?WorkOrchestrationDecision
    {
        return $this->authored($turn, $itemFence, $seatFence, $now, function () use ($turn, $eventId, $decision, $rationale, $evidence): WorkOrchestrationDecision {
            WorkOrchestrationEvent::query()->whereKey($eventId)->where('work_task_id', $turn->work_task_id)
                ->where('sequence', '>', $turn->turn_cursor)->where('sequence', '<=', $turn->turn_upper_bound)->firstOrFail();

            return WorkOrchestrationDecision::query()->create([
                'work_task_id' => $turn->work_task_id, 'triggering_event_id' => $eventId,
                'decision' => $decision, 'rationale' => $rationale,
                'evidence' => array_filter($evidence, fn ($value): bool => $value !== null),
            ]);
        });
    }

    public function queueChild(WorkItem $turn, int $itemFence, int $seatFence, WorkTask $child, string $role, CarbonImmutable $now): ?WorkItem
    {
        return DB::transaction(function () use ($turn, $itemFence, $seatFence, $child, $role, $now): ?WorkItem {
            [$locked, $seat] = $this->guardTurn($turn, $itemFence, $seatFence, $now);
            $ancestors = $this->locks->tasks($child->id);
            if ($child->id === $locked->work_task_id || ! in_array($locked->work_task_id, array_map(fn (WorkTask $task): string => $task->id, $ancestors), true)
                || in_array($role, [WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR], true)) {
                throw new DomainException('Expected an ordinary child item in the orchestrator subtree.');
            }

            return $this->queueOrdinaryChild($locked->workTask, $seat, $child, $role);
        }, 5);
    }

    /** Caller holds the parent/root locks and has proved that child is a descendant. */
    public function queueOrdinaryChild(WorkTask $parent, WorkOrchestratorSeat $seat, WorkTask $child, string $role): ?WorkItem
    {
        if (in_array($role, [WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR], true)) {
            throw new DomainException('Expected an ordinary child item in the orchestrator subtree.');
        }
        if ($seat->children_created >= max(1, (int) config('capstan.work.orchestration.child_budget', 100))) {
            $this->stop($parent, $seat, 'child_budget');

            return null;
        }
        $seat->children_created++;
        $seat->save();

        return $child->items()->create(['role' => $role]);
    }

    public function requestWake(WorkTask $parent, string $key, CarbonImmutable $dueAt, string $reason): WorkWake
    {
        return DB::transaction(function () use ($parent, $key, $dueAt, $reason): WorkWake {
            $this->locks->tasks($parent->id);

            return WorkWake::query()->firstOrCreate(['work_task_id' => $parent->id, 'request_key' => $key], ['due_at' => $dueAt, 'reason' => $reason]);
        }, 5);
    }

    /** Validate caller-supplied options into a bounded list of nonempty strings.
     * @param  array<array-key, mixed>  $options
     */
    public function escalate(WorkTask $parent, string $key, string $actor, string $question, array $options, string $recommendation): WorkEscalation
    {
        if (trim($question) === '' || mb_strlen($question) > 10000 || ! array_is_list($options)
            || count($options) < 1 || count($options) > 10 || trim($recommendation) === '' || mb_strlen($recommendation) > 2000) {
            throw new DomainException('Escalation requires a bounded question, options and recommendation.');
        }
        foreach ($options as $option) {
            if (! is_string($option) || trim($option) === '' || mb_strlen($option) > 2000) {
                throw new DomainException('Escalation options must be nonempty bounded strings.');
            }
        }

        return DB::transaction(function () use ($parent, $key, $actor, $question, $options, $recommendation): WorkEscalation {
            $this->locks->tasks($parent->id);

            return $this->createEscalation($parent, $key, $actor, $question, ['options' => $options, 'recommendation' => $recommendation]);
        }, 5);
    }

    /** @param array<string, mixed> $metadata */
    public function signalOwner(WorkTask $root, string $key, string $question, array $metadata): WorkEscalation
    {
        return DB::transaction(function () use ($root, $key, $question, $metadata): WorkEscalation {
            $this->locks->tasks($root->id);

            return $this->createEscalation(
                $root,
                $key,
                $root->owner_actor,
                $question,
                $metadata + [
                    'options' => ['Review the dependent task', 'Cancel the dependent task'],
                    'recommendation' => 'Review the dependent task',
                ],
            );
        }, 5);
    }

    public function answer(WorkEscalation $escalation, string $actor, string $answer, CarbonImmutable $now): WorkEscalation
    {
        return DB::transaction(function () use ($escalation, $actor, $answer, $now): WorkEscalation {
            $this->locks->tasks($escalation->work_task_id);
            $locked = $escalation->fresh();
            if ($locked->addressed_actor !== $actor) {
                throw new DomainException('Escalation is addressed to another actor.');
            }
            if ($locked->answered_at === null) {
                $item = WorkItem::query()->whereKey($locked->work_item_id)->lockForUpdate()->firstOrFail();
                $parent = WorkTask::query()->findOrFail($locked->work_task_id);
                if ($item->work_task_id !== $parent->id || $item->role !== WorkItem::ROLE_HUMAN
                    || $item->addressed_actor !== $actor || $item->state !== WorkItemState::Queued || $parent->cancelled_at !== null) {
                    throw new DomainException('Escalation item is unavailable.');
                }
                $locked->fill(['answer' => $answer, 'answered_by' => $actor, 'answered_at' => $now])->save();
                $from = $item->state;
                $item->state = WorkItemState::Acted;
                $item->save();
                $item->transitions()->create([
                    'from_state' => $from, 'to_state' => WorkItemState::Acted,
                    'actor_kind' => 'human', 'actor_id' => $actor, 'fence' => $item->fence, 'occurred_at' => $now,
                ]);
                // An answer is external input, even if requested during an authored operation.
                $this->event($parent, 'answer:'.$locked->id, 'escalation_answered', ['escalation_id' => $locked->id], inheritAuthor: false);
            }

            return $locked;
        }, 5);
    }

    public function dueWakes(CarbonImmutable $now): void
    {
        foreach (WorkWake::query()->whereNull('fired_at')->where('due_at', '<=', $now)->get() as $wake) {
            DB::transaction(function () use ($wake, $now): void {
                $this->locks->tasks($wake->work_task_id);
                $updated = WorkWake::query()->whereKey($wake->id)->whereNull('fired_at')->where('due_at', '<=', $now)->update(['fired_at' => $now]);
                if ($updated === 1) {
                    $this->event(WorkTask::query()->findOrFail($wake->work_task_id), 'wake:'.$wake->id, 'wake_due', ['wake_id' => $wake->id], inheritAuthor: false);
                }
            }, 5);
        }
    }

    private function stop(WorkTask $parent, WorkOrchestratorSeat $seat, string $reason): void
    {
        if ($seat->stopped_at !== null) {
            return;
        }
        $seat->stopped_at = CarbonImmutable::now();
        $seat->save();
        if ($seat->active_item_id !== null) {
            $item = WorkItem::query()->findOrFail($seat->active_item_id);
            if (! $item->state->isTerminal()) {
                $from = $item->state;
                $item->state = WorkItemState::Cancelled;
                $item->save();
                $item->transitions()->create([
                    'from_state' => $from, 'to_state' => WorkItemState::Cancelled,
                    'actor_kind' => 'system', 'actor_id' => 'budget', 'fence' => $item->fence, 'occurred_at' => CarbonImmutable::now(),
                ]);
            }
            $seat->active_item_id = null;
            $seat->save();
        }
        $this->createEscalation($parent, 'circuit-breaker', $parent->user_actor_address ?? $parent->creator_actor,
            'Automatic orchestration budget exhausted.', ['options' => ['Review the task before resuming', 'Cancel the task'], 'recommendation' => 'Review the task before resuming', 'reason' => $reason, 'turns' => $seat->turns_completed, 'turns_started' => $seat->turns_started, 'children' => $seat->children_created]);
    }

    /** @param array<string, mixed> $metadata */
    private function createEscalation(WorkTask $parent, string $key, string $actor, string $question, array $metadata): WorkEscalation
    {
        $existing = WorkEscalation::query()->where('work_task_id', $parent->id)->where('request_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $item = $parent->items()->create(['role' => WorkItem::ROLE_HUMAN, 'addressed_actor' => $actor]);

        $escalation = WorkEscalation::query()->create([
            'work_task_id' => $parent->id, 'work_item_id' => $item->id, 'request_key' => $key,
            'addressed_actor' => $actor, 'question' => $question, 'metadata' => $metadata,
        ]);
        $escalation->forceFill(['notified_at' => CarbonImmutable::now()])->save();
        resolve(WorkNotifier::class)->escalated($escalation);

        return $escalation;
    }
}
