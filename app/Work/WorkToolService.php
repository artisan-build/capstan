<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\WorkItem;
use App\Models\WorkSteer;
use App\Models\WorkTask;
use Illuminate\Support\Facades\DB;

final readonly class WorkToolService
{
    public function __construct(
        private BriefRevisionService $briefs,
        private WorkItemStateService $states,
        private WorkRepresentation $representation,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function write(string $tool, array $input, string $actor): array
    {
        return DB::transaction(function () use ($tool, $input, $actor): array {
            if ($tool === 'create_task') {
                if (isset($input['device_spoke_id']) && ! Spoke::query()->active()
                    ->whereKey($input['device_spoke_id'])->lockForUpdate()->exists()) {
                    throw new ApiErrorException(409, 'spoke_unavailable', 'Work host is unavailable.');
                }
                $task = WorkTask::query()->create([
                    ...array_intersect_key($input, array_flip(['team_id', 'title', 'user_actor_address', 'device_spoke_id', 'failover', 'required_capabilities', 'timebox_minutes'])),
                    'creator_actor' => $actor,
                ]);
                $revision = $this->briefs->append($task, $input['brief'], $actor);

                return ['task' => $this->representation->task($task), 'revision' => $this->representation->revision($revision)];
            }

            // Task-before-item locking also serializes cancellation against queue/revise/steer.
            $taskId = $input['task_id'] ?? WorkItem::query()
                ->whereKey($input['item_id'])
                ->whereHas('workTask', fn ($query) => $query->where('team_id', $input['team_id']))
                ->value('work_task_id');
            if (is_string($taskId) && WorkTask::query()->whereKey($taskId)->where('team_id', $input['team_id'])->exists()) {
                resolve(WorkOrchestrationLock::class)->tasks($taskId);
            }
            $task = $this->task($input['team_id'], $taskId, true);

            if ($tool === 'cancel_item') {
                $item = $task->items()->whereKey($input['item_id'])->lockForUpdate()->first();
                if ($item === null || $item->state->isTerminal()) {
                    throw new ApiErrorException(409, 'item_unavailable', 'Work item is unavailable.');
                }
                $item = $this->states->transition($item, WorkItemState::Cancelled, 'user', $actor);

                return ['item' => $this->representation->item($item->load('milestones'))];
            }

            if ($task->cancelled_at !== null) {
                throw new ApiErrorException(409, 'task_cancelled', 'Work task is cancelled.');
            }

            if ($tool === 'queue_item' && in_array($input['role'], [WorkItem::ROLE_ORCHESTRATOR, WorkItem::ROLE_HUMAN], true)) {
                throw new ApiErrorException(409, 'reserved_role', 'Reserved Work roles require orchestration or escalation.');
            }

            return match ($tool) {
                'revise_brief' => ['revision' => $this->representation->revision($this->briefs->append($task, $input['brief'], $actor))],
                'queue_item' => ['item' => $this->representation->item($task->items()->create(['role' => $input['role']])->load('milestones'))],
                'steer' => $this->steer($task, $input, $actor),
                'cancel_task' => $this->cancelTask($task, $actor),
                default => throw new \LogicException('Unknown Work write.'),
            };
        });
    }

    public function task(int $teamId, ?string $taskId, bool $lock = false): WorkTask
    {
        $task = WorkTask::query()->where('team_id', $teamId)->whereKey($taskId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($task === null) {
            throw new ApiErrorException(404, 'task_unavailable', 'Work task is unavailable.');
        }

        return $task;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function steer(WorkTask $task, array $input, string $actor): array
    {
        if (isset($input['item_id']) && ! $task->items()->whereKey($input['item_id'])->exists()) {
            throw new ApiErrorException(404, 'item_unavailable', 'Work item is unavailable.');
        }
        $steer = WorkSteer::query()->create([
            'work_task_id' => $task->id,
            'work_item_id' => $input['item_id'] ?? null,
            'text' => $input['content'],
            'author_actor' => $actor,
        ]);

        return ['steer' => $this->representation->steer($steer)];
    }

    /** @return array<string, mixed> */
    private function cancelTask(WorkTask $task, string $actor): array
    {
        $task->forceFill(['cancelled_at' => now()])->save();
        $task->items()->orderBy('id')->chunkById(100, function ($items) use ($actor): void {
            foreach ($items as $item) {
                // Re-read under the state service lock; a worker may have just finished.
                $locked = WorkItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                if (! $locked->state->isTerminal()) {
                    $this->states->transition($locked, WorkItemState::Cancelled, 'user', $actor);
                }
            }
        });

        return ['task' => $this->representation->task($task)];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function read(string $tool, array $input): array
    {
        if ($tool === 'get_task') {
            $task = $this->task($input['team_id'], $input['task_id']);
            $task->load([
                'briefRevisions', 'items' => fn ($query) => $query->orderBy('id'),
                'items.milestones' => fn ($query) => $query->orderBy('id'),
                'milestones' => fn ($query) => $query->orderBy('id'),
                'steers' => fn ($query) => $query->orderBy('id'),
            ]);

            return [
                'task' => $this->representation->task($task),
                'revisions' => $task->briefRevisions->map($this->representation->revision(...))->all(),
                'items' => $task->items->map($this->representation->item(...))->all(),
                'milestones' => $task->milestones->map($this->representation->milestone(...))->all(),
                'steers' => $task->steers->map($this->representation->steer(...))->all(),
            ];
        }

        if (isset($input['task_id'])) {
            $this->task($input['team_id'], $input['task_id']);
        }
        $limit = $input['limit'] ?? 25;
        $items = WorkItem::query()
            ->whereHas('workTask', fn ($query) => $query->where('team_id', $input['team_id']))
            ->when(isset($input['task_id']), fn ($query) => $query->where('work_task_id', $input['task_id']))
            ->when(isset($input['state']), fn ($query) => $query->where('state', $input['state']))
            ->with(['milestones' => fn ($query) => $query->orderByDesc('id')->limit(1)])
            ->orderBy('id')->limit($limit + 1)->get();
        $groups = [];
        foreach (WorkItemState::cases() as $state) {
            $groups[$state->value] = $items->take($limit)->filter(fn (WorkItem $item): bool => $item->state === $state)
                ->map($this->representation->item(...))->values()->all();
        }

        return ['groups' => $groups, 'limit' => $limit, 'has_more' => $items->count() > $limit];
    }
}
