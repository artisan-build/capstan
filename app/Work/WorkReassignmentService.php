<?php

namespace App\Work;

use App\Enums\WorkPriority;
use App\Http\ApiErrorException;
use App\Machine\TeamMemberships;
use App\Mcp\RetryableWriteException;
use App\Models\Spoke;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkTask;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class WorkReassignmentService
{
    public const string MODE_MOVE = 'move';

    public const string MODE_ESCALATIONS = 'escalations';

    public function __construct(
        private WorkOrchestrationLock $locks,
        private WorkSeatService $seats,
        private WorkOrchestrationService $orchestration,
        private WorkNotifier $notifier,
        private TeamMemberships $memberships,
    ) {}

    /** @return array{task_id: string, old_owner: string, new_owner: string, old_escalation_actor: string, new_escalation_actor: string, mode: string} */
    public function reassign(
        int $teamId,
        string $taskId,
        string $actorId,
        string $targetActor,
        string $mode,
        ?string $note,
        string $sourceKey,
    ): array {
        return DB::transaction(function () use ($teamId, $taskId, $actorId, $targetActor, $mode, $note, $sourceKey): array {
            $root = WorkTask::query()
                ->whereKey($taskId)
                ->where('team_id', $teamId)
                ->whereNull('parent_task_id')
                ->first();
            if (! $root instanceof WorkTask) {
                throw $this->refused('task_unavailable');
            }

            $this->locks->tasks($root->id);
            $root->refresh();
            $caller = User::query()->find($actorId);
            $callerIsMember = $caller instanceof User && $this->memberships->belongsTo($caller, $teamId);
            $callerIsManager = $caller instanceof User
                && RolePolicy::canManageMembers($caller->role)
                && $callerIsMember;
            if ($root->owner_actor !== $actorId && ! $callerIsMember) {
                throw $this->refused('task_unavailable');
            }
            if ($root->owner_actor !== $actorId && ! $callerIsManager) {
                throw $this->refused('reassignment_forbidden');
            }
            if (! in_array($mode, [self::MODE_MOVE, self::MODE_ESCALATIONS], true)) {
                throw $this->refused('invalid_mode');
            }

            $target = User::query()->whereKey($targetActor)->where('status', 'active')->first();
            if (! $target instanceof User
                || ! RolePolicy::canUseProduct($target->role)
                || OffboardedSubject::userIsOffboarded($targetActor)
                || ! $this->memberships->belongsTo($target, $teamId)) {
                throw $this->refused('invalid_target');
            }
            $oldEscalationActor = $root->effectiveEscalationActor();
            if (($mode === self::MODE_MOVE && $root->owner_actor === $targetActor && $oldEscalationActor === $targetActor)
                || ($mode === self::MODE_ESCALATIONS && $oldEscalationActor === $targetActor)) {
                throw $this->refused('reassignment_unchanged');
            }
            if ($mode === self::MODE_MOVE && $root->priority === WorkPriority::P0
                && WorkTask::query()
                    ->where('owner_actor', $targetActor)
                    ->whereNull('parent_task_id')
                    ->whereNull('cancelled_at')
                    ->where('priority', WorkPriority::P0)
                    ->whereKeyNot($root->id)
                    ->exists()) {
                throw $this->refused('active_p0_exists');
            }

            $oldOwner = $root->owner_actor;
            $taskIds = $this->subtreeIds($root);
            try {
                $updates = $mode === self::MODE_MOVE
                    ? ['owner_actor' => $targetActor, 'escalation_actor' => $targetActor, 'user_actor_address' => $targetActor]
                    : ['escalation_actor' => $targetActor];
                WorkTask::query()->whereKey($taskIds)->update([...$updates, 'updated_at' => CarbonImmutable::now()]);
            } catch (QueryException $exception) {
                if ($mode === self::MODE_MOVE && $root->priority === WorkPriority::P0
                    && (str_contains($exception->getMessage(), 'work_tasks_one_active_p0_per_owner')
                        || str_contains($exception->getMessage(), 'work_tasks.owner_actor'))) {
                    throw $this->refused('active_p0_exists');
                }

                throw $exception;
            }
            if ($mode === self::MODE_MOVE) {
                WorkTask::query()
                    ->whereKey($taskIds)
                    ->whereNotNull('device_spoke_id')
                    ->whereNotIn('device_spoke_id', Spoke::query()->select('id')->where('actor_id', $targetActor))
                    ->update(['device_spoke_id' => null, 'updated_at' => CarbonImmutable::now()]);
            }

            $openEscalations = WorkEscalation::query()
                ->whereIn('work_task_id', $taskIds)
                ->whereNull('answered_at')
                ->get(['id', 'work_item_id']);
            WorkEscalation::query()->whereKey($openEscalations->pluck('id')->all())
                ->update(['addressed_actor' => $targetActor, 'updated_at' => CarbonImmutable::now()]);
            WorkItem::query()
                ->whereKey($openEscalations->pluck('work_item_id')->all())
                ->where('role', WorkItem::ROLE_HUMAN)
                ->where('state', 'queued')
                ->update(['addressed_actor' => $targetActor, 'updated_at' => CarbonImmutable::now()]);

            if ($mode === self::MODE_MOVE) {
                $this->seats->releaseForReassignment($root, CarbonImmutable::now());
            }

            $root->refresh();
            $this->orchestration->event(
                $root,
                'reassignment:'.$sourceKey,
                'task_reassigned',
                [
                    'old_owner' => $oldOwner,
                    'new_owner' => $root->owner_actor,
                    'old_escalation_actor' => $oldEscalationActor,
                    'new_escalation_actor' => $root->effectiveEscalationActor(),
                    'mode' => $mode,
                    'note' => $note,
                ],
                inheritAuthor: false,
            );
            try {
                $this->notifier->reassigned($root, $oldOwner, $root->owner_actor, $mode);
            } catch (QueryException $exception) {
                throw new RetryableWriteException('A transactional notification write failed.', previous: $exception);
            }

            return [
                'task_id' => $root->id,
                'old_owner' => $oldOwner,
                'new_owner' => $root->owner_actor,
                'old_escalation_actor' => $oldEscalationActor,
                'new_escalation_actor' => $root->effectiveEscalationActor(),
                'mode' => $mode,
            ];
        }, 5);
    }

    /** @return list<string> */
    private function subtreeIds(WorkTask $root): array
    {
        $ids = [$root->id];
        for ($offset = 0; $offset < count($ids); $offset++) {
            $children = WorkTask::query()
                ->where('parent_task_id', $ids[$offset])
                ->orderBy('id')
                ->pluck('id')
                ->all();
            array_push($ids, ...$children);
        }

        return $ids;
    }

    private function refused(string $code): ApiErrorException
    {
        return new ApiErrorException(403, $code, 'The requested Work reassignment is unavailable.');
    }
}
