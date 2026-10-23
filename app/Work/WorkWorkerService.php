<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Models\Spoke;
use App\Models\WorkDirective;
use App\Models\WorkEvidence;
use App\Models\WorkItem;
use App\Models\WorkMilestone;
use App\Models\WorkSteer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class WorkWorkerService
{
    public function __construct(private WorkEvidenceService $evidence) {}

    /** @return Builder<WorkItem> */
    public function recoveryQuery(): Builder
    {
        return WorkItem::query()->whereIn('state', ['claimed', 'delivered', 'routed'])
            ->whereNotNull('claimant_spoke_id')->whereNotNull('claimant_actor')
            ->whereHas('workTask', fn ($query) => $query->whereNull('cancelled_at'));
    }

    /** @return Builder<WorkItem> */
    public function activeQuery(): Builder
    {
        return $this->recoveryQuery()
            ->whereIn('claimant_spoke_id', Spoke::query()->workEligible()->select('id'));
    }

    /**
     * The conditional write serializes callbacks on both drivers. Locks alone are
     * not the fence: every write rechecks the claim, including cancellation.
     *
     * @template T
     *
     * @param  Closure(WorkItem): T  $operation
     * @return T
     */
    public function guarded(string $id, int $fence, Closure $operation, ?Closure $afterIdentified = null, ?string $nonce = null): mixed
    {
        return DB::transaction(function () use ($id, $fence, $operation, $afterIdentified, $nonce): mixed {
            $item = WorkItem::query()->with('workTask')->find($id);
            abort_if($item === null, 409, 'claim lost');
            abort_if($item->state === WorkItemState::Cancelled || $item->workTask->cancelled_at !== null, 410, 'cancelled');
            abort_unless(in_array($item->state, [WorkItemState::Claimed, WorkItemState::Delivered, WorkItemState::Routed], true)
                && $item->fence === $fence && $item->claimant_spoke_id !== null && $item->claimant_actor !== null, 409, 'claim lost');
            if ($nonce !== null) {
                abort_unless($item->launch_nonce !== null && hash_equals($item->launch_nonce, $nonce), 409, 'launch nonce mismatch');
            }
            $spokeLocked = Spoke::query()->workEligible()
                ->whereKey($item->claimant_spoke_id)
                ->where('actor_id', $item->claimant_actor)
                ->update(['updated_at' => DB::raw('updated_at')]);
            abort_unless($spokeLocked === 1, 409, 'claim lost');
            $afterIdentified?->__invoke($item);
            resolve(WorkOrchestrationLock::class)->tasks($item->work_task_id);
            $updated = $this->activeQuery()->whereKey($id)->where('fence', $fence)
                ->where('claimant_spoke_id', $item->claimant_spoke_id)
                ->where('claimant_actor', $item->claimant_actor)
                ->when($nonce !== null, fn (Builder $query) => $query->where('launch_nonce', $nonce))
                ->update(['fetched_brief_revision_id' => DB::raw('fetched_brief_revision_id')]);
            abort_unless($updated === 1, 409, 'claim lost');

            return $operation($item->refresh());
        }, 5);
    }

    /** @return array<string, mixed> */
    public function brief(string $id, int $fence, string $nonce, ?string $model = null, ?Closure $afterIdentified = null): array
    {
        return $this->guarded($id, $fence, function (WorkItem $item) use ($model): array {
            $revision = $item->workTask->briefRevisions()->reorder()->orderByDesc('sequence')->first();
            $receipt = [
                'fetched_brief_revision_id' => $revision?->id,
                'fetched_page_version_id' => $item->workTask->brief_page_version_id,
                'agent_model' => $model,
            ];
            if ($item->first_fetched_at === null) {
                $receipt['first_fetched_at'] = CarbonImmutable::now();
            }
            $updated = $this->activeQuery()->whereKey($item->id)->where('fence', $item->fence)->update($receipt);
            abort_unless($updated === 1, 409, 'claim lost');
            WorkDirective::query()->where('work_item_id', $item->id)->where('fence', $item->fence)
                ->where('status', 'pending')->update([
                    'status' => 'succeeded',
                    'outcome' => ['result' => 'succeeded', 'receipt' => true],
                    'completed_at' => CarbonImmutable::now(),
                ]);

            return array_filter([
                'task' => array_filter([
                    'id' => $item->work_task_id,
                    'title' => $item->workTask->title,
                    'workflow_ref' => $item->workTask->workflow_ref,
                    'workflow_sha' => $item->workTask->workflow_sha,
                    'brief_page_id' => $item->workTask->brief_page_id,
                    'brief_page_version_id' => $item->workTask->brief_page_version_id,
                ], fn ($value): bool => $value !== null),
                'item' => ['id' => $item->id, 'role' => $item->role, 'fence' => $item->fence],
                'revision' => $revision === null ? null : ['id' => $revision->id, 'sequence' => $revision->sequence, 'content' => $revision->content],
                ...WorkPreemptionService::representation($item),
            ], fn ($value): bool => $value !== null);
        }, $afterIdentified, $nonce);
    }

    /** @param array{url?: string, summary?: string, pr?: array{repo: string, number: int, url: string}} $payload */
    public function milestone(string $id, int $fence, WorkMilestoneKind $kind, array $payload, ?Closure $afterIdentified = null, ?string $nonce = null): WorkMilestone
    {
        return $this->guarded($id, $fence, function (WorkItem $item) use ($kind, $payload): WorkMilestone {
            if ($kind === WorkMilestoneKind::PullRequestOpened) {
                $pr = $payload['pr'] ?? null;
                abort_unless(is_array($pr), 422, 'PR metadata is required.');
                $task = $item->workTask;
                if ($task->pr !== null && $task->pr !== $pr) {
                    throw new HttpException(409, 'PR metadata is already set.');
                }
                $existing = $item->milestones()->where('kind', $kind->value)->get()
                    ->first(fn (WorkMilestone $milestone): bool => ($milestone->payload['pr'] ?? null) === $pr);
                if ($existing instanceof WorkMilestone) {
                    return $existing;
                }
                if ($task->pr === null) {
                    $task->forceFill(['pr' => $pr])->save();
                }
            }
            $now = CarbonImmutable::now();
            if ($item->milestones()->where('created_at', '>', $now->subMinutes(10))->count() >= 30) {
                throw new HttpException(429, 'Too many milestones.');
            }

            $target = match ($kind) {
                WorkMilestoneKind::Done => WorkItemState::Acted,
                WorkMilestoneKind::Failed => WorkItemState::Failed,
                WorkMilestoneKind::Released => WorkItemState::Queued,
                default => null,
            };
            if (in_array($target, [WorkItemState::Acted, WorkItemState::Failed], true)) {
                abort_if($item->role === WorkItem::ROLE_ORCHESTRATOR && $target === WorkItemState::Acted, 422, 'Orchestrator turns require turn_done.');
                abort_unless($item->state === WorkItemState::Routed, 422, 'Illegal transition.');
            }

            $from = $item->state;
            if (isset($payload['summary']) && trim($payload['summary']) !== '') {
                $item->last_progress = $payload['summary'];
            }
            if ($target !== null) {
                $item->state = $target;
                $item->fill(WorkPreemptionService::cleared());
                if ($target === WorkItemState::Queued) {
                    $item->attempts++;
                    $item->claimant_actor = null;
                    $item->claimant_spoke_id = null;
                    $item->lease_until = null;
                    $item->claimed_at = null;
                    $item->stalled_at = null;
                }
                $item->transitions()->create([
                    'from_state' => $from, 'to_state' => $target,
                    'actor_kind' => 'link', 'actor_id' => $item->id,
                    'fence' => $item->fence, 'occurred_at' => $now,
                ]);
            }
            $item->save();

            $milestone = $item->milestones()->create(['fence' => $item->fence, 'kind' => $kind, 'payload' => $payload]);
            resolve(WorkEventProjector::class)->terminal($item);
            resolve(WorkEventProjector::class)->blocked($item, $milestone);

            return $milestone;
        }, $afterIdentified, $nonce);
    }

    public function evidence(string $id, int $fence, string $filename, string $bytes, ?string $nonce = null): WorkEvidence
    {
        $metadata = $this->evidence->inspect($filename, $bytes);
        $attempts = new WorkEvidenceAttemptLog;

        return $attempts->settle(fn (): WorkEvidence => $this->guarded(
            $id,
            $fence,
            function (WorkItem $item) use ($bytes, $metadata, $attempts): WorkEvidence {
                $this->evidence->throttle('actor:'.(string) $item->claimant_actor);

                return $this->evidence->store($item, $bytes, $metadata, $attempts);
            },
            nonce: $nonce,
        ));
    }

    /** @return list<array<string, mixed>> */
    public function steers(string $id, int $fence, ?string $nonce = null): array
    {
        return $this->guarded($id, $fence, function (WorkItem $item): array {
            $now = CarbonImmutable::now();
            $steers = WorkSteer::query()->where('work_task_id', $item->work_task_id)
                ->where(fn ($query) => $query->whereNull('work_item_id')->orWhere('work_item_id', $item->id))
                ->where(fn ($query) => $query->whereNull('read_at')->orWhere('created_at', '>=', $now->subSeconds(max(0, (int) config('capstan.work.steer_recent_seconds', 86400)))))->oldest()->orderBy('id')->get();
            WorkSteer::query()->whereKey($steers->modelKeys())->whereNull('read_at')->update(['read_at' => $now]);

            return array_values($steers->map(fn (WorkSteer $steer): array => array_filter([
                'id' => $steer->id, 'task_id' => $steer->work_task_id, 'item_id' => $steer->work_item_id,
                'text' => $steer->text, 'author_actor' => $steer->author_actor,
                'created_at' => $steer->created_at->toISOString(),
                'read_at' => ($steer->fresh()->read_at)->toISOString(),
            ], fn ($value): bool => $value !== null))->all());
        }, nonce: $nonce);
    }
}
