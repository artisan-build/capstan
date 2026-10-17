<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Models\WorkItem;
use App\Models\WorkMilestone;
use App\Models\WorkSteer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class WorkWorkerService
{
    /** @return Builder<WorkItem> */
    public function activeQuery(): Builder
    {
        return WorkItem::query()->whereIn('state', ['claimed', 'delivered', 'routed'])
            ->whereNotNull('claimant_spoke_id')->whereNotNull('claimant_actor')
            ->whereHas('workTask', fn ($query) => $query->whereNull('cancelled_at'));
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
    public function guarded(string $id, int $fence, Closure $operation, ?Closure $afterIdentified = null): mixed
    {
        return DB::transaction(function () use ($id, $fence, $operation, $afterIdentified): mixed {
            $item = WorkItem::query()->with('workTask')->find($id);
            abort_if($item === null, 409, 'claim lost');
            abort_if($item->state === WorkItemState::Cancelled || $item->workTask->cancelled_at !== null, 410, 'cancelled');
            $afterIdentified?->__invoke($item);
            $updated = $this->activeQuery()->whereKey($id)->where('fence', $fence)
                ->where('claimant_spoke_id', $item->claimant_spoke_id)
                ->where('claimant_actor', $item->claimant_actor)
                ->update(['fetched_brief_revision_id' => DB::raw('fetched_brief_revision_id')]);
            abort_unless($updated === 1, 409, 'claim lost');

            return $operation($item->refresh());
        }, 5);
    }

    /** @return array<string, mixed> */
    public function brief(string $id, int $fence, ?Closure $afterIdentified = null): array
    {
        return $this->guarded($id, $fence, function (WorkItem $item): array {
            $revision = $item->workTask->briefRevisions()->reorder()->orderByDesc('sequence')->first();
            $updated = $this->activeQuery()->whereKey($item->id)->where('fence', $item->fence)
                ->update(['fetched_brief_revision_id' => $revision?->id]);
            abort_unless($updated === 1, 409, 'claim lost');

            return array_filter([
                'task' => ['id' => $item->work_task_id, 'title' => $item->workTask->title],
                'item' => ['id' => $item->id, 'role' => $item->role, 'fence' => $item->fence],
                'revision' => $revision === null ? null : ['id' => $revision->id, 'sequence' => $revision->sequence, 'content' => $revision->content],
            ], fn ($value): bool => $value !== null);
        }, $afterIdentified);
    }

    /** @param array{url?: string, summary?: string} $payload */
    public function milestone(string $id, int $fence, WorkMilestoneKind $kind, array $payload, ?Closure $afterIdentified = null): WorkMilestone
    {
        return $this->guarded($id, $fence, function (WorkItem $item) use ($kind, $payload): WorkMilestone {
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
                abort_unless($item->state === WorkItemState::Routed, 422, 'Illegal transition.');
            }

            $from = $item->state;
            if (isset($payload['summary']) && trim($payload['summary']) !== '') {
                $item->last_progress = $payload['summary'];
            }
            if ($target !== null) {
                $item->state = $target;
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

            return $item->milestones()->create(['fence' => $item->fence, 'kind' => $kind, 'payload' => $payload]);
        }, $afterIdentified);
    }

    /** @return list<array<string, mixed>> */
    public function steers(string $id, int $fence): array
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
        });
    }
}
