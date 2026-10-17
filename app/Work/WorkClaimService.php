<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Models\WorkItem;
use App\Models\WorkItemTransition;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use stdClass;
use Throwable;

final class WorkClaimService
{
    private const int CANDIDATE_PAGE_SIZE = 200;

    private const int SQLITE_LOCK_ATTEMPTS = 50;

    /**
     * @param  non-empty-list<string>  $roles
     * @param  list<string>  $capabilities
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
    ): array {
        if ($capacity === 0) {
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
            );

            if ($candidates === []) {
                break;
            }

            $won = 0;

            foreach ($candidates as $candidate) {
                $item = $this->claimCandidate(
                    $candidate,
                    $teamId,
                    $actorId,
                    $spokeId,
                    $now,
                    $leaseSeconds,
                );

                if ($item !== null) {
                    $claimed[] = $item;
                    $won++;
                }

                if (count($claimed) === $capacity) {
                    break;
                }
            }

            if ($won === 0 && $this->candidates($teamId, $actorId, $spokeId, $roles, $capabilities, 1) === []) {
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
     * @return list<stdClass>
     */
    private function candidates(
        int $teamId,
        string $actorId,
        int $spokeId,
        array $roles,
        array $capabilities,
        int $wanted,
    ): array {
        $eligible = [];
        $afterCreatedAt = null;
        $afterId = null;

        do {
            $query = DB::table('work_items')
                ->join('work_tasks', 'work_tasks.id', '=', 'work_items.work_task_id')
                ->where('work_tasks.team_id', $teamId)
                ->whereNull('work_tasks.cancelled_at')
                ->where('work_items.state', WorkItemState::Queued->value)
                ->whereIn('work_items.role', $roles)
                ->where('work_items.role', '!=', WorkItem::ROLE_HUMAN)
                ->where(function ($query) use ($actorId): void {
                    $query->whereNull('work_tasks.user_actor_address')
                        ->orWhere('work_tasks.user_actor_address', $actorId);
                })
                ->where(function ($query) use ($spokeId): void {
                    $query->whereNull('work_tasks.device_spoke_id')
                        ->orWhere('work_tasks.device_spoke_id', $spokeId);
                })
                ->select([
                    'work_items.id',
                    'work_items.work_task_id',
                    'work_items.role',
                    'work_items.fence',
                    'work_items.created_at',
                    'work_tasks.updated_at as task_updated_at',
                    'work_tasks.required_capabilities',
                ])
                ->oldest('work_items.created_at')
                ->orderBy('work_items.id')
                ->limit(self::CANDIDATE_PAGE_SIZE);

            if ($afterCreatedAt !== null && $afterId !== null) {
                $query->where(function ($query) use ($afterCreatedAt, $afterId): void {
                    $query->where('work_items.created_at', '>', $afterCreatedAt)
                        ->orWhere(function ($query) use ($afterCreatedAt, $afterId): void {
                            $query->where('work_items.created_at', $afterCreatedAt)
                                ->where('work_items.id', '>', $afterId);
                        });
                });
            }

            $page = $query->get();

            foreach ($page as $candidate) {
                if ($this->hasEveryCapability($candidate->required_capabilities, $capabilities)) {
                    $eligible[] = $candidate;
                }

                if (count($eligible) === $wanted) {
                    break 2;
                }
            }

            $last = $page->last();
            $afterCreatedAt = $last?->created_at;
            $afterId = $last?->id;
        } while ($page->count() === self::CANDIDATE_PAGE_SIZE);

        return $eligible;
    }

    /** @param list<string> $declared */
    private function hasEveryCapability(mixed $requiredValue, array $declared): bool
    {
        $required = is_string($requiredValue)
            ? json_decode($requiredValue, true)
            : $requiredValue;

        if (! is_array($required)) {
            return false;
        }

        return array_diff($required, $declared) === [];
    }

    private function claimCandidate(
        stdClass $candidate,
        int $teamId,
        string $actorId,
        int $spokeId,
        CarbonImmutable $now,
        int $leaseSeconds,
    ): ?WorkItem {
        $attempt = 0;

        beginning:
        try {
            return DB::transaction(function () use ($candidate, $teamId, $actorId, $spokeId, $now, $leaseSeconds): ?WorkItem {
                $nextFence = ((int) $candidate->fence) + 1;
                $leaseUntil = $now->addSeconds($leaseSeconds);
                $updated = WorkItem::query()
                    ->whereKey($candidate->id)
                    ->where('work_task_id', $candidate->work_task_id)
                    ->where('role', $candidate->role)
                    ->where('state', WorkItemState::Queued->value)
                    ->where('fence', $candidate->fence)
                    ->whereHas('workTask', function ($query) use ($candidate, $teamId, $actorId, $spokeId): void {
                        $query->where('team_id', $teamId)
                            ->whereNull('cancelled_at')
                            ->where('updated_at', $candidate->task_updated_at)
                            ->where(function ($query) use ($actorId): void {
                                $query->whereNull('user_actor_address')->orWhere('user_actor_address', $actorId);
                            })
                            ->where(function ($query) use ($spokeId): void {
                                $query->whereNull('device_spoke_id')->orWhere('device_spoke_id', $spokeId);
                            });
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
                    return null;
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

                return WorkItem::query()->whereKey($candidate->id)->firstOrFail();
            });
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
}
