<?php

namespace App\Work;

use App\Enums\WorkPriority;
use App\Http\ApiErrorException;
use App\Machine\TeamMemberships;
use App\Models\WorkOrchestrationDecision;
use App\Models\WorkTask;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class WorkPriorityService
{
    public function __construct(
        private WorkOrchestrationLock $locks,
        private WorkClaimService $claims,
        private WorkOrchestrationService $orchestration,
        private TeamMemberships $memberships,
    ) {}

    public function authorize(int $teamId, string $taskId, string $actor): WorkTask
    {
        $user = User::query()->find($actor);
        $root = WorkTask::query()->whereKey($taskId)->where('team_id', $teamId)->whereNull('parent_task_id')->whereNull('cancelled_at')->first();
        if (! $user instanceof User || $user->status !== 'active' || ! RolePolicy::canUseProduct($user->role)
            || OffboardedSubject::userIsOffboarded($actor) || $root === null
            || ($root->owner_actor !== $actor && (! RolePolicy::canManageMembers($user->role) || ! $this->memberships->belongsTo($user, $teamId)))) {
            throw new ApiErrorException(403, 'task_unavailable', 'The requested Work priority operation is unavailable.');
        }

        return $root;
    }

    /** @return list<string> */
    private function subtree(WorkTask $root): array
    {
        $ids = [$root->id];
        for ($offset = 0; $offset < count($ids); $offset++) {
            array_push($ids, ...WorkTask::query()->where('parent_task_id', $ids[$offset])->orderBy('id')->pluck('id')->all());
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function plan(WorkTask $root, CarbonImmutable $now): array
    {
        $plan = $this->claims->preemptionPlan($root, $this->subtree($root), $now);
        if (count($plan) > 25) {
            throw new ApiErrorException(409, 'preemption_plan_too_large', 'The exact preemption inventory exceeds the confirmation budget.');
        }
        $inventory = array_map(fn (array $entry): array => array_diff_key($entry, array_flip(['fence', 'opportunity_id', 'state', 'priority'])), $plan);

        return [
            'task_id' => $root->id,
            'confirmation_token' => hash('sha256', json_encode([$root->id, $root->priority->value, $plan], JSON_THROW_ON_ERROR)),
            'preemptions' => $inventory,
        ];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function change(array $input, string $actor, string $sourceKey): array
    {
        return DB::transaction(function () use ($input, $actor, $sourceKey): array {
            $root = $this->authorize($input['team_id'], $input['task_id'], $actor);
            // The work_tasks database guard acquires the team's plan revision row.
            // Every row class read by plan() takes the same guard before mutation.
            $this->locks->tasks($root->id);
            $root = $this->authorize($input['team_id'], $root->id, $actor);
            $now = CarbonImmutable::now();
            $priority = WorkPriority::from($input['priority']);
            $old = $root->priority;
            $plan = [];
            if ($priority === WorkPriority::P0) {
                $plan = $this->plan($root, $now);
                if (! isset($input['confirmation_token'])) {
                    return [...$plan, 'priority_changed' => false, 'confirmation_status' => 'required'];
                }
                if (! hash_equals($plan['confirmation_token'], $input['confirmation_token'])) {
                    return [...$plan, 'priority_changed' => false, 'confirmation_status' => 'refused', 'reason' => 'preemption_plan_changed'];
                }
            }
            if ($priority === $old) {
                return ['task_id' => $root->id, 'priority_changed' => false];
            }
            try {
                // A nested transaction supplies a savepoint for PostgreSQL's unique violation.
                DB::transaction(fn () => WorkTask::query()->whereKey($this->subtree($root))->update(['priority' => $priority, 'updated_at' => $now]));
            } catch (QueryException $exception) {
                if ($priority === WorkPriority::P0 && (str_contains($exception->getMessage(), 'work_tasks_one_active_p0_per_owner') || str_contains($exception->getMessage(), 'work_tasks.owner_actor'))) {
                    throw new ApiErrorException(409, 'active_p0_exists', 'The owner already has an active P0 Work task.');
                }
                throw $exception;
            }
            $evidence = ['old_priority' => $old->value, 'new_priority' => $priority->value, 'preemptions' => $plan['preemptions'] ?? []];
            $event = $this->orchestration->event($root->refresh(), 'priority:'.$sourceKey, 'priority_changed', $evidence, inheritAuthor: false);
            if ($priority === WorkPriority::P0) {
                WorkOrchestrationDecision::query()->create([
                    'work_task_id' => $root->id, 'triggering_event_id' => $event->id,
                    'decision' => 'Set root priority to P0', 'rationale' => $input['reason'], 'evidence' => $evidence,
                ]);
            }

            return ['task_id' => $root->id, 'priority_changed' => true, 'priority' => $priority->value];
        }, 5);
    }
}
