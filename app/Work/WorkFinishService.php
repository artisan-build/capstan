<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\WorkDirective;
use App\Models\WorkFinish;
use App\Models\WorkItem;
use App\Models\WorkOrchestrationEvent;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkSteer;
use App\Models\WorkTask;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class WorkFinishService
{
    public function __construct(
        private WorkOrchestrationLock $locks,
        private WorkOrchestrationService $orchestration,
        private WorkTaskGate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $pr
     * @param  Closure(WorkItem): void|null  $afterIdentified
     */
    public function declare(
        string $itemId,
        int $fence,
        string $launchNonce,
        string $writeup,
        array $pr,
        ?Closure $afterIdentified = null,
    ): WorkFinish {
        $writeup = trim($writeup);
        $this->assertWriteup($writeup);
        $this->assertPullRequest($pr);

        return DB::transaction(function () use ($itemId, $fence, $launchNonce, $writeup, $pr, $afterIdentified): WorkFinish {
            $item = WorkItem::query()->with('workTask')->find($itemId);
            abort_if($item === null, 409, 'claim lost');
            abort_if($item->state === WorkItemState::Cancelled || $item->workTask->cancelled_at !== null, 410, 'cancelled');
            abort_unless(! in_array($item->role, [WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR], true)
                && in_array($item->state, [WorkItemState::Routed, WorkItemState::AwaitingVerification], true)
                && $item->fence === $fence && $item->claimant_spoke_id !== null && $item->claimant_actor !== null
                && $item->launch_nonce !== null && hash_equals($item->launch_nonce, $launchNonce)
                && $item->lease_until !== null && $item->lease_until->isAfter(CarbonImmutable::now()), 409, 'claim lost');
            $afterIdentified?->__invoke($item);
            $this->locks->tasks($item->work_task_id);
            $item = WorkItem::query()->with('workTask')->whereKey($itemId)->lockForUpdate()->firstOrFail();
            abort_unless(! in_array($item->role, [WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR], true)
                && in_array($item->state, [WorkItemState::Routed, WorkItemState::AwaitingVerification], true)
                && $item->fence === $fence && $item->claimant_spoke_id !== null && $item->claimant_actor !== null
                && $item->launch_nonce !== null && hash_equals($item->launch_nonce, $launchNonce)
                && $item->lease_until !== null && $item->lease_until->isAfter(CarbonImmutable::now()), 409, 'claim lost');
            abort_unless(Spoke::query()->workEligible()->whereKey($item->claimant_spoke_id)
                ->where('actor_id', $item->claimant_actor)->exists(), 409, 'claim lost');

            $active = WorkFinish::query()->where('work_item_id', $item->id)->where('fence', $item->fence)
                ->where('status', 'awaiting')->lockForUpdate()->first();
            if ($item->state === WorkItemState::AwaitingVerification) {
                abort_unless($active instanceof WorkFinish
                    && hash_equals($active->writeup, $writeup) && $active->pr === $pr, 409, 'finish already declared');

                return $active;
            }
            abort_if($active instanceof WorkFinish, 409, 'finish already declared');

            $task = $item->workTask;
            if ($task->pr !== null && $task->pr !== $pr) {
                throw new HttpException(409, 'PR metadata is already set.');
            }
            if ($task->pr === null) {
                $task->forceFill(['pr' => $pr])->save();
            }

            $finish = WorkFinish::query()->create([
                'work_item_id' => $item->id,
                'fence' => $item->fence,
                'writeup' => $writeup,
                'pr' => $pr,
            ]);
            $from = $item->state;
            $item->forceFill([
                'state' => WorkItemState::AwaitingVerification,
                'work_deadline_at' => null,
                'first_deadline_missed_at' => null,
                'stalled_at' => null,
            ])->save();
            $item->transitions()->create([
                'from_state' => $from,
                'to_state' => WorkItemState::AwaitingVerification,
                'actor_kind' => 'orb',
                'actor_id' => $item->claimant_actor,
                'fence' => $item->fence,
                'occurred_at' => CarbonImmutable::now(),
            ]);
            $this->settleNudges($item, 'resolved_by_finish', CarbonImmutable::now());

            $parent = $this->gate->root($task);
            $event = $this->orchestration->event(
                $parent,
                'finish:'.$finish->id,
                'child_awaiting_verification',
                [
                    'item_id' => $item->id,
                    'task_id' => $item->work_task_id,
                    'fence' => $item->fence,
                    'finish_id' => $finish->id,
                    'writeup' => $writeup,
                    'pr' => $pr,
                ],
                inheritAuthor: false,
            );
            $finish->forceFill(['orchestration_event_id' => $event->id])->save();

            return $finish->refresh();
        }, 5);
    }

    public function review(
        WorkTask $parent,
        WorkOrchestratorSeat $seat,
        ?WorkItem $turn,
        string $finishId,
        string $itemId,
        string $verdict,
        ?string $corrections,
        CarbonImmutable $now,
    ): WorkFinish {
        $candidate = WorkItem::query()->whereKey($itemId)->first();
        if (! $candidate instanceof WorkItem) {
            throw $this->forbidden();
        }
        $ancestors = $this->locks->tasks($candidate->work_task_id);
        if (! in_array($parent->id, array_map(fn (WorkTask $task): string => $task->id, $ancestors), true)) {
            throw $this->forbidden();
        }

        $finish = WorkFinish::query()->whereKey($finishId)->lockForUpdate()->first();
        $item = WorkItem::query()->whereKey($itemId)->lockForUpdate()->first();
        if (! $finish instanceof WorkFinish || ! $item instanceof WorkItem
            || $finish->work_item_id !== $item->id || $item->role === WorkItem::ROLE_ORCHESTRATOR
            || $item->role === WorkItem::ROLE_HUMAN) {
            throw $this->forbidden();
        }
        $event = WorkOrchestrationEvent::query()->whereKey($finish->orchestration_event_id)
            ->where('work_task_id', $parent->id)->first();
        $lower = $turn instanceof WorkItem ? (int) ($turn->turn_cursor ?? $seat->completed_cursor) : $seat->completed_cursor;
        $upper = $turn instanceof WorkItem ? (int) ($turn->turn_upper_bound ?? $seat->event_sequence) : $seat->event_sequence;
        if (! $event instanceof WorkOrchestrationEvent || $event->sequence <= $lower || $event->sequence > $upper) {
            throw $this->forbidden();
        }

        $corrections = $corrections === null ? null : trim($corrections);
        if ($finish->status !== 'awaiting') {
            if ($finish->status === $verdict && $finish->correction_text === $corrections) {
                return $finish;
            }

            throw $this->forbidden();
        }
        if ($item->state !== WorkItemState::AwaitingVerification || $item->fence !== $finish->fence
            || $item->claimant_actor === null || $item->claimant_spoke_id === null
            || $item->workTask->cancelled_at !== null) {
            throw $this->forbidden();
        }

        $audit = [
            'status' => $verdict,
            'verdict_actor' => $seat->holder_actor,
            'verdict_spoke_id' => $seat->holder_spoke_id,
            'verdict_seat_fence' => $seat->fence,
            'verdict_turn_item_id' => $turn?->id,
            'verdict_turn_fence' => $turn?->fence,
            'verdict_at' => $now,
        ];
        if ($verdict === 'accept') {
            $milestone = $item->milestones()->create([
                'fence' => $item->fence,
                'kind' => WorkMilestoneKind::Done,
                'payload' => ['summary' => $finish->writeup],
            ]);
            $from = $item->state;
            $item->forceFill([
                'state' => WorkItemState::Acted,
                'last_progress' => $finish->writeup,
                'work_deadline_at' => null,
                'first_deadline_missed_at' => null,
                'stalled_at' => null,
                ...WorkPreemptionService::cleared(),
            ])->save();
            $item->transitions()->create([
                'from_state' => $from,
                'to_state' => WorkItemState::Acted,
                'actor_kind' => 'orchestrator',
                'actor_id' => (string) $seat->holder_actor,
                'fence' => $item->fence,
                'occurred_at' => $now,
            ]);
            $finish->forceFill([...$audit, 'terminal_milestone_id' => $milestone->id])->save();
            resolve(WorkEventProjector::class)->terminal($item);

            return $finish->refresh();
        }

        if ($corrections === null || $corrections === '' || mb_strlen($corrections) > WorkFinish::CORRECTION_MAX_LENGTH) {
            throw $this->forbidden();
        }
        $steer = WorkSteer::query()->create([
            'work_task_id' => $item->work_task_id,
            'work_item_id' => $item->id,
            'text' => $corrections,
            'author_actor' => (string) $seat->holder_actor,
        ]);
        $from = $item->state;
        $item->forceFill([
            'state' => WorkItemState::Routed,
            'work_deadline_at' => $now->addMinutes($item->workTask->timebox_minutes),
            'first_deadline_missed_at' => null,
            'stalled_at' => null,
        ])->save();
        $item->transitions()->create([
            'from_state' => $from,
            'to_state' => WorkItemState::Routed,
            'actor_kind' => 'orchestrator',
            'actor_id' => (string) $seat->holder_actor,
            'fence' => $item->fence,
            'occurred_at' => $now,
        ]);
        $finish->forceFill([
            ...$audit,
            'correction_text' => $corrections,
            'correction_steer_id' => $steer->id,
        ])->save();

        return $finish->refresh();
    }

    private function assertWriteup(string $writeup): void
    {
        abort_if($writeup === '' || mb_strlen($writeup) > WorkFinish::WRITEUP_MAX_LENGTH, 422, 'Invalid finish writeup.');
    }

    /** @param array<string, mixed> $pr */
    private function assertPullRequest(array $pr): void
    {
        $keys = array_keys($pr);
        sort($keys);
        $repo = $pr['repo'] ?? null;
        $number = $pr['number'] ?? null;
        $url = $pr['url'] ?? null;
        $canonical = is_string($repo) && is_int($number) && $number > 0
            ? 'https://github.com/'.$repo.'/pull/'.$number
            : null;
        abort_unless($keys === ['number', 'repo', 'url']
            && is_string($repo) && preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/D', $repo) === 1
            && is_string($url) && $url === $canonical, 422, 'Invalid pull request metadata.');
    }

    private function settleNudges(WorkItem $item, string $result, CarbonImmutable $now): void
    {
        WorkDirective::query()->where('work_item_id', $item->id)->where('fence', $item->fence)
            ->whereIn('reason', ['deadline', 'stall'])->where('status', 'pending')->update([
                'status' => 'succeeded',
                'outcome' => ['result' => $result],
                'completed_at' => $now,
            ]);
    }

    private function forbidden(): ApiErrorException
    {
        return new ApiErrorException(403, 'orchestration_forbidden', 'The finish verdict is unavailable.');
    }
}
