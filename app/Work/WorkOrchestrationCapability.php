<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Features\Work;
use App\Http\ApiErrorException;
use App\Models\WorkBriefRevision;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestrationDecision;
use App\Models\WorkOrchestrationEvent;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkStateRevision;
use App\Models\WorkSteer;
use App\Models\WorkTask;
use App\Models\WorkWake;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/** The single auditable boundary for writes made with an orchestrator authority. */
final readonly class WorkOrchestrationCapability
{
    public function __construct(
        private WorkOrchestrationLock $locks,
        private WorkOrchestrationService $orchestration,
        private BriefRevisionService $briefs,
        private WorkItemStateService $states,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createChild(WorkOrchestrationContext $context, string $underTaskId, array $attributes, string $brief, CarbonImmutable $now): WorkTask
    {
        return $this->authorised($context, $now, function (WorkTask $parent) use ($underTaskId, $attributes, $brief, $context): WorkTask {
            $under = $this->target($parent, $underTaskId, allowParent: true);
            $child = WorkTask::query()->create([
                ...array_intersect_key($attributes, array_flip(['title', 'user_actor_address', 'device_spoke_id', 'failover', 'required_capabilities', 'timebox_minutes', 'workflow_ref'])),
                'team_id' => $parent->team_id,
                'parent_task_id' => $under->id,
                'creator_actor' => $context->actorId,
            ]);
            $this->briefs->append($child, $brief, $context->actorId);

            return $child;
        });
    }

    public function reviseBrief(WorkOrchestrationContext $context, string $taskId, string $brief, CarbonImmutable $now): WorkBriefRevision
    {
        return $this->authorised($context, $now, fn (WorkTask $parent): WorkBriefRevision => $this->briefs->append(
            $this->target($parent, $taskId), $brief, $context->actorId,
        ));
    }

    public function queue(WorkOrchestrationContext $context, string $taskId, string $role, CarbonImmutable $now): ?WorkItem
    {
        return $this->authorised($context, $now, function (WorkTask $parent, WorkOrchestratorSeat $seat) use ($taskId, $role): ?WorkItem {
            if (in_array($role, [WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR], true)) {
                throw $this->forbidden('Reserved roles are outside ordinary orchestration queue authority.');
            }

            return $this->orchestration->queueOrdinaryChild($parent, $seat, $this->target($parent, $taskId), $role);
        });
    }

    /** @return array<string, mixed> */
    public function context(WorkOrchestrationContext $context, ?int $after, CarbonImmutable $now, ?string $decisionsAfter = null): array
    {
        return $this->authorised($context, $now, function (WorkTask $parent, WorkOrchestratorSeat $seat, ?WorkItem $turn) use ($after, $decisionsAfter): array {
            $brief = $parent->latestBriefRevision()->first();
            $state = WorkStateRevision::query()->where('work_task_id', $parent->id)->orderByDesc('sequence')->first();
            $floor = $turn instanceof WorkItem ? (int) ($turn->turn_cursor ?? $seat->completed_cursor) : $seat->completed_cursor;
            $upper = $turn instanceof WorkItem ? (int) ($turn->turn_upper_bound ?? $seat->event_sequence) : $seat->event_sequence;
            if ($after !== null && ($after > $upper || $after < $floor)) {
                throw new ApiErrorException(422, 'invalid_cursor', 'The orchestration cursor is outside the authorised interval.');
            }
            $lower = $after ?? $floor;
            $events = WorkOrchestrationEvent::query()->where('work_task_id', $parent->id)
                ->where('sequence', '>', $lower)->where('sequence', '<=', $upper)->orderBy('sequence')->limit(26)->get();
            $decisionQuery = WorkOrchestrationDecision::query()->where('work_task_id', $parent->id);
            if ($decisionsAfter !== null) {
                $decisionQuery->where('id', '>', $decisionsAfter);
            }
            $decisions = $decisionQuery->orderBy('id')->limit(26)->get();
            $decisionPage = $decisions->take(25)->values();

            return array_filter([
                'task' => array_filter([
                    'id' => $parent->id, 'team_id' => $parent->team_id, 'title' => $parent->title,
                    'workflow_ref' => $parent->workflow_ref,
                ], fn (mixed $value): bool => $value !== null),
                'brief' => $brief === null ? null : ['id' => $brief->id, 'sequence' => $brief->sequence, 'content' => $brief->content],
                'state' => $state === null ? null : [
                    'id' => $state->id, 'sequence' => $state->sequence,
                    'through' => $state->through_cursor, 'content' => $state->content,
                ],
                'decisions' => $decisionPage->map(fn (WorkOrchestrationDecision $decision): array => [
                    'id' => $decision->id, 'event_id' => $decision->triggering_event_id,
                    'decision' => $decision->decision, 'rationale' => $decision->rationale,
                    'evidence' => $this->boundedJson($decision->evidence),
                ])->all(),
                'events' => $events->take(25)->map(fn (WorkOrchestrationEvent $event): array => [
                    'id' => $event->id, 'sequence' => $event->sequence, 'kind' => $event->kind,
                    'evidence' => $this->boundedJson($event->evidence),
                ])->values()->all(),
                'cursor' => $lower, 'next_cursor' => $events->count() > 25 ? (int) $events[24]->sequence : null,
                'through' => $upper, 'has_more' => $events->count() > 25,
                'decisions_cursor' => $decisionsAfter, 'decisions_next_cursor' => $decisions->count() > 25 ? (string) $decisionPage->last()->id : null,
                'decisions_has_more' => $decisions->count() > 25,
            ], fn (mixed $value): bool => $value !== null);
        });
    }

    public function cancelItem(WorkOrchestrationContext $context, string $itemId, CarbonImmutable $now): WorkItem
    {
        return $this->authorised($context, $now, function (WorkTask $parent) use ($itemId, $context, $now): WorkItem {
            $item = WorkItem::query()->whereKey($itemId)->first();
            if (! $item instanceof WorkItem) {
                throw $this->forbidden();
            }
            $this->target($parent, $item->work_task_id);
            $item = WorkItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            if (in_array($item->role, [WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR], true) || $item->state->isTerminal()) {
                throw $this->forbidden('Only live ordinary subtree items may be cancelled.');
            }

            return $this->states->transition($item, WorkItemState::Cancelled, 'orchestrator', $context->actorId, $now);
        });
    }

    public function steer(WorkOrchestrationContext $context, string $taskId, ?string $itemId, string $content, CarbonImmutable $now): WorkSteer
    {
        return $this->authorised($context, $now, function (WorkTask $parent) use ($taskId, $itemId, $content, $context): WorkSteer {
            $target = $this->target($parent, $taskId);
            if ($itemId !== null && ! $target->items()->whereKey($itemId)->exists()) {
                throw $this->forbidden();
            }

            return WorkSteer::query()->create([
                'work_task_id' => $target->id,
                'work_item_id' => $itemId,
                'text' => $content,
                'author_actor' => $context->actorId,
            ]);
        });
    }

    /** @param array<string, mixed> $evidence */
    public function decide(WorkOrchestrationContext $context, string $eventId, string $decision, string $rationale, array $evidence, CarbonImmutable $now): WorkOrchestrationDecision
    {
        return $this->authorised($context, $now, function (WorkTask $parent, WorkOrchestratorSeat $seat, ?WorkItem $turn) use ($eventId, $decision, $rationale, $evidence): WorkOrchestrationDecision {
            $lower = $turn instanceof WorkItem ? ($turn->turn_cursor ?? $seat->completed_cursor) : $seat->completed_cursor;
            $upper = $turn instanceof WorkItem ? ($turn->turn_upper_bound ?? $seat->event_sequence) : $seat->event_sequence;
            WorkOrchestrationEvent::query()->whereKey($eventId)->where('work_task_id', $parent->id)
                ->where('sequence', '>', $lower)->where('sequence', '<=', $upper)->firstOrFail();

            return WorkOrchestrationDecision::query()->create([
                'work_task_id' => $parent->id,
                'triggering_event_id' => $eventId,
                'decision' => $decision,
                'rationale' => $rationale,
                'evidence' => array_filter($evidence, fn (mixed $value): bool => $value !== null),
            ]);
        });
    }

    public function requestWake(WorkOrchestrationContext $context, string $key, CarbonImmutable $dueAt, string $reason, CarbonImmutable $now): WorkWake
    {
        return $this->authorised($context, $now, fn (WorkTask $parent): WorkWake => WorkWake::query()->firstOrCreate(
            ['work_task_id' => $parent->id, 'request_key' => $key],
            ['due_at' => $dueAt, 'reason' => $reason],
        ));
    }

    /** @param list<string> $options */
    public function escalate(WorkOrchestrationContext $context, string $key, string $addressedActor, string $question, array $options, string $recommendation, CarbonImmutable $now): WorkEscalation
    {
        return $this->authorised($context, $now, fn (WorkTask $parent): WorkEscalation => $this->orchestration->escalate(
            $parent, $key, $addressedActor, $question, $options, $recommendation,
        ));
    }

    public function finish(WorkOrchestrationContext $context, WorkItemState $state, ?string $content, CarbonImmutable $now): WorkItem
    {
        if (! in_array($state, [WorkItemState::Acted, WorkItemState::Failed], true)) {
            throw $this->forbidden('An orchestrator may only act or fail its parent turn.');
        }

        return $this->authorised($context, $now, function (WorkTask $parent, WorkOrchestratorSeat $seat, ?WorkItem $turn) use ($state, $content, $context, $now): WorkItem {
            if ($turn !== null) {
                if ($state === WorkItemState::Acted) {
                    $this->orchestration->turnDone($turn, (int) $context->itemFence, $context->seatFence, (string) $content, $now);

                    return $turn->refresh();
                }
                if ($turn->state !== WorkItemState::Routed) {
                    throw $this->forbidden('A drain turn must be routed before it can fail.');
                }
                $failed = $this->states->transition($turn, WorkItemState::Failed, 'orchestrator', $context->actorId, $now);
                $this->orchestration->ended($failed);

                return $failed;
            }

            $item = WorkItem::query()->whereKey($seat->active_item_id)->lockForUpdate()->first();
            if (! $item instanceof WorkItem || $item->role !== WorkItem::ROLE_ORCHESTRATOR || $item->state !== WorkItemState::Queued) {
                throw $this->forbidden('The interactive parent turn is unavailable.');
            }
            $from = $item->state;
            $item->state = $state;
            $item->save();
            $item->transitions()->create([
                'from_state' => $from, 'to_state' => $state,
                'actor_kind' => 'orchestrator', 'actor_id' => $context->actorId,
                'fence' => $item->fence, 'occurred_at' => $now,
            ]);
            $seat->turns_started++;
            if ($state === WorkItemState::Acted) {
                WorkStateRevision::query()->create([
                    'work_task_id' => $parent->id,
                    'work_item_id' => $item->id,
                    'sequence' => $seat->turns_completed + 1,
                    'through_cursor' => $seat->event_sequence,
                    'content' => (string) $content,
                ]);
                $seat->completed_cursor = $seat->event_sequence;
                $seat->turns_completed++;
            }
            $seat->active_item_id = null;
            $seat->save();
            resolve(WorkEventProjector::class)->terminal($item);
            $this->orchestration->queue($parent, $seat->refresh());

            return $item;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(WorkTask, WorkOrchestratorSeat, WorkItem|null): T  $write
     * @return T
     */
    private function authorised(WorkOrchestrationContext $context, CarbonImmutable $now, Closure $write): mixed
    {
        if (! Feature::active(Work::class)) {
            throw new ApiErrorException(404, 'work_unavailable', 'Work is unavailable.');
        }

        return DB::transaction(function () use ($context, $now, $write): mixed {
            $parent = WorkTask::query()->whereKey($context->parentTaskId)->first();
            if (! $parent instanceof WorkTask) {
                throw $this->forbidden();
            }
            $this->locks->tasks($parent->id);
            $seat = $this->locks->seat($parent);
            $turn = null;

            if ($context->isInteractive()) {
                if (! $seat->interactive || $seat->fence !== $context->seatFence
                    || $seat->holder_actor !== $context->actorId || $seat->holder_spoke_id !== $context->spokeId
                    || $seat->lease_until === null || $seat->lease_until->lte($now)
                    || $seat->session_ref === null || $context->sessionRef === null || ! hash_equals($seat->session_ref, $context->sessionRef)
                    || $seat->stopped_at !== null || $parent->fresh()->cancelled_at !== null) {
                    throw $this->forbidden('Interactive orchestrator authority was lost.');
                }
            } else {
                $turn = WorkItem::query()->whereKey($context->turnItemId)->first();
                if (! $turn instanceof WorkItem || $context->itemFence === null) {
                    throw $this->forbidden();
                }
                try {
                    [$turn, $seat] = $this->orchestration->guardTurn($turn, $context->itemFence, $context->seatFence, $now);
                } catch (\Throwable) {
                    throw $this->forbidden('Orchestrator turn authority was lost.');
                }
                if ($turn->work_task_id !== $parent->id || $seat->holder_actor !== $context->actorId
                    || $seat->holder_spoke_id !== $context->spokeId) {
                    throw $this->forbidden();
                }
            }

            return WorkOrchestrationAuthor::run($parent->id, fn (): mixed => $write($parent, $seat, $turn));
        }, 5);
    }

    private function target(WorkTask $parent, string $taskId, bool $allowParent = false): WorkTask
    {
        try {
            $ancestors = $this->locks->tasks($taskId);
        } catch (ModelNotFoundException) {
            throw $this->forbidden();
        }
        $target = end($ancestors);
        $ids = array_map(fn (WorkTask $task): string => $task->id, $ancestors);
        if (! $target instanceof WorkTask || $target->team_id !== $parent->team_id
            || ! in_array($parent->id, $ids, true) || (! $allowParent && $target->id === $parent->id)
            || $target->cancelled_at !== null) {
            throw $this->forbidden();
        }

        return $target;
    }

    private function forbidden(string $message = 'Target is outside the current orchestrator subtree.'): ApiErrorException
    {
        return new ApiErrorException(403, 'orchestration_forbidden', $message);
    }

    /** @param array<string, mixed> $value */
    private function boundedJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) && strlen($json) <= 8000 ? $json : '{"truncated":true}';
    }
}
