<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Machine\MachineWorkRuntime;
use App\Models\MachineTeamWorkSetting;
use App\Models\Spoke;
use App\Models\WorkItem;
use App\Models\WorkItemTransition;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use stdClass;
use Throwable;

final readonly class WorkClaimService
{
    public function __construct(
        private WorkSeatService $seats,
        private WorkOrchestrationLock $locks,
        private MachineWorkRuntime $runtime,
        private MachineWorkArbiter $arbiter,
        private WorkTaskGate $gate,
    ) {}

    private const int CANDIDATE_PAGE_SIZE = 200;

    private const int SQLITE_LOCK_ATTEMPTS = 50;

    /**
     * @param  non-empty-list<string>  $roles
     * @param  list<string>  $capabilities
     * @param  array<string, string>  $roleProviders
     * @return list<WorkItem>
     */
    public function claim(
        int $teamId,
        string $actorId,
        int $spokeId,
        array $roles,
        array $capabilities,
        int $capacity,
        CarbonImmutable $now,
        int $leaseSeconds,
        array $roleProviders = [],
        bool $requireMachineProfile = false,
        ?Closure $afterCandidates = null,
        ?Closure $afterDependencyGate = null,
    ): array {
        if ($capacity === 0 || ! $this->activeSpokeExists($actorId, $spokeId, $teamId, $roles, $capabilities, $roleProviders, $now, $requireMachineProfile)) {
            return [];
        }

        $claimed = [];

        while (count($claimed) < $capacity) {
            $candidates = $this->candidates(
                $teamId,
                $actorId,
                $spokeId,
                $roles,
                $capabilities,
                $capacity - count($claimed),
                $now,
                $roleProviders,
                $requireMachineProfile,
            );

            if ($candidates === []) {
                break;
            }

            $afterCandidates?->__invoke();
            $afterCandidates = null;

            $won = 0;

            foreach ($candidates as $candidate) {
                $item = $this->claimCandidate(
                    $candidate,
                    $teamId,
                    $actorId,
                    $spokeId,
                    $now,
                    $leaseSeconds,
                    $roles,
                    $capabilities,
                    $roleProviders,
                    $requireMachineProfile,
                    $afterDependencyGate,
                );
                $afterDependencyGate = null;

                if ($item !== null) {
                    $claimed[] = $item;
                    $won++;
                }

                if (count($claimed) === $capacity) {
                    break;
                }
            }

            if ($won === 0 && (! $this->activeSpokeExists($actorId, $spokeId, $teamId, $roles, $capabilities, $roleProviders, $now, $requireMachineProfile)
                || $this->candidates($teamId, $actorId, $spokeId, $roles, $capabilities, 1, $now, $roleProviders, $requireMachineProfile) === [])) {
                break;
            }
        }

        return $claimed;
    }

    /**
     * Select in stable queue order, filtering JSON capabilities in PHP so SQLite and
     * PostgreSQL use identical subset semantics. The write below guards the exact task
     * revision observed here as well as the item's state and fence.
     *
     * @param  non-empty-list<string>  $roles
     * @param  list<string>  $capabilities
     * @param  array<string, string>  $roleProviders
     * @return list<stdClass>
     */
    private function candidates(
        int $teamId,
        string $actorId,
        int $spokeId,
        array $roles,
        array $capabilities,
        int $wanted,
        CarbonImmutable $now,
        array $roleProviders = [],
        bool $requireMachineProfile = false,
    ): array {
        if ($requireMachineProfile) {
            $setting = $this->runtime->eligibleSetting($teamId, $actorId, $spokeId, $roles, $capabilities, $roleProviders, $now);
            if (! $setting instanceof MachineTeamWorkSetting
                || $this->runtime->authoritativeClaimedCount($spokeId) >= $setting->concurrency_ceiling) {
                return [];
            }
            $wanted = min($wanted, $setting->concurrency_ceiling - $this->runtime->authoritativeClaimedCount($spokeId));
        }
        $eligible = [];
        $afterPriority = null;
        $afterId = null;

        do {
            $query = DB::table('work_items')
                ->join('work_tasks', 'work_tasks.id', '=', 'work_items.work_task_id')
                ->where('work_tasks.team_id', $teamId)
                ->whereNull('work_tasks.cancelled_at')
                ->where(fn ($query) => $query->whereNull('work_tasks.expires_at')->orWhere('work_tasks.expires_at', '>', $now))
                ->where('work_items.state', WorkItemState::Queued->value)
                ->whereIn('work_items.role', $roles)
                ->where('work_items.role', '!=', WorkItem::ROLE_HUMAN)
                ->where(function ($query) use ($actorId): void {
                    $query->where('work_tasks.user_actor_address', $actorId)
                        ->orWhere(function ($query) use ($actorId): void {
                            $query->whereNull('work_tasks.user_actor_address')
                                ->where(function ($query) use ($actorId): void {
                                    $query->where('work_tasks.routing_pool_work', true)
                                        ->orWhere('work_tasks.owner_actor', $actorId);
                                });
                        });
                })
                ->where(function ($query) use ($spokeId): void {
                    $query->whereNull('work_tasks.device_spoke_id')
                        ->orWhere('work_tasks.device_spoke_id', $spokeId)
                        ->orWhere('work_items.role', WorkItem::ROLE_ORCHESTRATOR);
                })
                ->select([
                    'work_items.id',
                    'work_items.work_task_id',
                    'work_items.role',
                    'work_items.fence',
                    'work_items.updated_at as item_updated_at',
                    'work_tasks.updated_at as task_updated_at',
                    'work_tasks.required_capabilities',
                    'work_tasks.device_spoke_id',
                    'work_tasks.user_actor_address',
                    'work_tasks.owner_actor',
                    'work_tasks.priority',
                    'work_tasks.routing_pool_work',
                ])
                ->orderBy('work_tasks.priority')
                ->orderBy('work_items.id')
                ->limit(self::CANDIDATE_PAGE_SIZE);

            if ($afterPriority !== null && $afterId !== null) {
                $query->where(function ($query) use ($afterPriority, $afterId): void {
                    $query->where('work_tasks.priority', '>', $afterPriority)
                        ->orWhere(function ($query) use ($afterPriority, $afterId): void {
                            $query->where('work_tasks.priority', $afterPriority)
                                ->where('work_items.id', '>', $afterId);
                        });
                });
            }

            $page = $query->get();

            foreach ($page as $candidate) {
                $task = WorkTask::query()->find($candidate->work_task_id);
                if (! $task instanceof WorkTask || ! $this->gate->claimable($task, $now)) {
                    continue;
                }
                if ($candidate->role === WorkItem::ROLE_ORCHESTRATOR) {
                    $seat = WorkOrchestratorSeat::query()->where('work_task_id', $candidate->work_task_id)->first();
                    if (! $this->seats->eligible(
                        $seat, $actorId, $spokeId, $now,
                        $candidate->device_spoke_id === null ? null : (int) $candidate->device_spoke_id,
                        $candidate->id, CarbonImmutable::parse($candidate->item_updated_at),
                    )) {
                        continue;
                    }
                    if (WorkItem::query()->where('work_task_id', $candidate->work_task_id)->where('role', WorkItem::ROLE_ORCHESTRATOR)
                        ->whereIn('state', ['claimed', 'delivered', 'routed'])->exists()) {
                        continue;
                    }
                    if ($seat?->active_item_id !== null && $seat->active_item_id !== $candidate->id
                        && WorkItem::query()->whereKey($seat->active_item_id)->whereIn('state', ['queued', 'claimed', 'delivered', 'routed'])->exists()) {
                        continue;
                    }
                }
                if ($this->hasEveryCapability($candidate->required_capabilities, $capabilities)
                    && (! $requireMachineProfile || ! $this->isPooledUnpinned($candidate)
                        || $this->arbiter->isWinner(
                            $teamId,
                            $candidate->role,
                            $this->capabilities($candidate->required_capabilities),
                            $spokeId,
                            $now,
                        ))) {
                    $eligible[] = $candidate;
                }

                if (count($eligible) === $wanted) {
                    break 2;
                }
            }

            $last = $page->last();
            $afterPriority = $last?->priority;
            $afterId = $last?->id;
        } while ($page->count() === self::CANDIDATE_PAGE_SIZE);

        return $eligible;
    }

    /** @param list<string> $declared */
    private function hasEveryCapability(mixed $requiredValue, array $declared): bool
    {
        $required = $this->capabilities($requiredValue);

        return array_diff($required, $declared) === [];
    }

    /** @return list<string> */
    private function capabilities(mixed $value): array
    {
        $value = is_string($value) ? json_decode($value, true) : $value;

        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $capabilities
     * @param  array<string, string>  $roleProviders
     */
    private function claimCandidate(
        stdClass $candidate,
        int $teamId,
        string $actorId,
        int $spokeId,
        CarbonImmutable $now,
        int $leaseSeconds,
        array $roles = [],
        array $capabilities = [],
        array $roleProviders = [],
        bool $requireMachineProfile = false,
        ?Closure $afterDependencyGate = null,
    ): ?WorkItem {
        $attempt = 0;

        beginning:
        try {
            return DB::transaction(function () use ($candidate, $teamId, $actorId, $spokeId, $now, $leaseSeconds, $roles, $capabilities, $roleProviders, $requireMachineProfile, $afterDependencyGate): ?WorkItem {
                if (! Spoke::query()->workEligible()->whereKey($spokeId)->where('actor_id', $actorId)->lockForUpdate()->exists()) {
                    return null;
                }
                $task = WorkTask::query()->whereKey($candidate->work_task_id)->first();
                if (! $task instanceof WorkTask) {
                    return null;
                }
                $dependencyVersions = $this->gate->lockDependencies($task);
                $task->refresh();
                if (! $this->gate->claimable($task, $now)) {
                    return null;
                }
                $afterDependencyGate?->__invoke($task, $dependencyVersions);
                if ($requireMachineProfile) {
                    if ($roles === []) {
                        return null;
                    }
                    $setting = $this->runtime->eligibleSetting($teamId, $actorId, $spokeId, $roles, $capabilities, $roleProviders, $now, true);
                    if (! $setting instanceof MachineTeamWorkSetting
                        || ! in_array($candidate->role, $roles, true)
                        || ! $this->hasEveryCapability($candidate->required_capabilities, $capabilities)
                        || $this->runtime->authoritativeClaimedCount($spokeId) >= $setting->concurrency_ceiling
                        || ($this->isPooledUnpinned($task) && ! $this->arbiter->isWinner(
                            $teamId,
                            $candidate->role,
                            $this->capabilities($candidate->required_capabilities),
                            $spokeId,
                            $now,
                        ))) {
                        return null;
                    }
                }
                $seat = null;
                if ($candidate->role === WorkItem::ROLE_ORCHESTRATOR) {
                    $this->locks->tasks($candidate->work_task_id);
                    $turn = WorkItem::query()->whereKey($candidate->id)->firstOrFail();
                    if ($turn->state !== WorkItemState::Queued || $turn->fence !== (int) $candidate->fence) {
                        return null;
                    }
                    $seat = $this->seats->forClaim($turn->workTask, $turn, $actorId, $spokeId, $now, $leaseSeconds);
                    if ($seat === null) {
                        return null;
                    }
                }
                $nextFence = ((int) $candidate->fence) + 1;
                $leaseUntil = $now->addSeconds($leaseSeconds);
                $updated = WorkItem::query()
                    ->whereKey($candidate->id)
                    ->where('work_task_id', $candidate->work_task_id)
                    ->where('role', $candidate->role)
                    ->where('state', WorkItemState::Queued->value)
                    ->where('fence', $candidate->fence)
                    ->whereHas('workTask', function ($query) use ($candidate, $teamId, $actorId, $spokeId, $seat, $now, $task, $dependencyVersions): void {
                        $query->where('team_id', $teamId)
                            ->whereNull('cancelled_at')
                            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                            ->where('updated_at', $candidate->task_updated_at)
                            ->where('dependency_version', $task->dependency_version)
                            ->where(function ($query) use ($actorId): void {
                                $query->where('user_actor_address', $actorId)
                                    ->orWhere(function ($query) use ($actorId): void {
                                        $query->whereNull('user_actor_address')
                                            ->where(function ($query) use ($actorId): void {
                                                $query->where('owner_actor', $actorId)
                                                    ->orWhere('routing_pool_work', true);
                                            });
                                    });
                            })
                            ->when($seat === null, fn ($query) => $query->where(function ($query) use ($spokeId): void {
                                $query->whereNull('device_spoke_id')->orWhere('device_spoke_id', $spokeId);
                            }));
                        foreach ($dependencyVersions as $dependencyId => $version) {
                            $query->whereHas('dependencies', fn ($query) => $query
                                ->whereKey($dependencyId)
                                ->where('dependency_version', $version)
                                ->whereNull('cancelled_at')
                                ->doesntHave('expiration')
                                ->has('items')
                                ->whereDoesntHave('items', fn ($query) => $query->where('state', '!=', WorkItemState::Acted->value)));
                        }
                    })
                    ->update([
                        'state' => WorkItemState::Claimed->value,
                        'claimant_spoke_id' => $spokeId,
                        'claimant_actor' => $actorId,
                        'lease_until' => $leaseUntil,
                        'claimed_at' => $now,
                        'waiting_on_host_at' => null,
                        'waiting_on_host_notified_at' => null,
                        'fence' => $nextFence,
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    throw new WorkClaimLost;
                }

                WorkItemTransition::query()->create([
                    'work_item_id' => $candidate->id,
                    'from_state' => WorkItemState::Queued,
                    'to_state' => WorkItemState::Claimed,
                    'actor_kind' => 'spoke',
                    'actor_id' => $actorId,
                    'fence' => $nextFence,
                    'occurred_at' => $now,
                ]);

                $claimed = WorkItem::query()->whereKey($candidate->id)->firstOrFail();
                if ($seat !== null) {
                    if (! resolve(WorkOrchestrationService::class)->startTurn($claimed->workTask, $seat, $claimed)) {
                        return null;
                    }
                    $claimed->fill([
                        'turn_cursor' => $claimed->turn_cursor ?? $seat->completed_cursor,
                        'turn_upper_bound' => $claimed->turn_upper_bound ?? $seat->event_sequence,
                        'seat_fence' => $seat->fence,
                    ])->save();
                    $claimed->session_ref = $seat->session_ref;
                }

                return $claimed;
            });
        } catch (WorkClaimLost) {
            return null;
        } catch (QueryException $exception) {
            $attempt++;

            if ($attempt < self::SQLITE_LOCK_ATTEMPTS && $this->isSqliteLock($exception)) {
                Sleep::usleep(random_int(1_000, 20_000));
                goto beginning;
            }

            throw $exception;
        }
    }

    private function isSqliteLock(Throwable $exception): bool
    {
        return DB::getDriverName() === 'sqlite'
            && str_contains(strtolower($exception->getMessage()), 'database is locked');
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $capabilities
     * @param  array<string, string>  $roleProviders
     *
     * @phpstan-impure
     */
    private function activeSpokeExists(
        string $actorId,
        int $spokeId,
        int $teamId,
        array $roles,
        array $capabilities,
        array $roleProviders,
        CarbonImmutable $now,
        bool $requireMachineProfile,
    ): bool {
        if ($requireMachineProfile) {
            if ($roles === []) {
                return false;
            }

            return $this->runtime->eligibleSetting($teamId, $actorId, $spokeId, $roles, $capabilities, $roleProviders, $now) instanceof MachineTeamWorkSetting;
        }

        return Spoke::query()->workEligible()->whereKey($spokeId)->where('actor_id', $actorId)->exists();
    }

    private function isPooledUnpinned(stdClass|WorkTask $candidate): bool
    {
        $pooled = (bool) $candidate->routing_pool_work;

        return $pooled
            && $candidate->user_actor_address === null
            && $candidate->device_spoke_id === null;
    }
}
