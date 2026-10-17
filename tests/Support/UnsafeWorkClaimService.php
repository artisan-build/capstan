<?php

namespace Tests\Support;

use App\Enums\WorkItemState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Positive control for the process-race test only. It deliberately selects before
 * synchronization and later writes without a state/fence predicate.
 */
final class UnsafeWorkClaimService
{
    /** @return list<string> */
    public function select(int $teamId, int $capacity): array
    {
        return DB::table('work_items')
            ->join('work_tasks', 'work_tasks.id', '=', 'work_items.work_task_id')
            ->where('work_tasks.team_id', $teamId)
            ->where('work_items.state', WorkItemState::Queued->value)
            ->where('work_items.role', 'builder')
            ->oldest('work_items.created_at')
            ->orderBy('work_items.id')
            ->limit($capacity)
            ->pluck('work_items.id')
            ->all();
    }

    /** @param list<string> $itemIds */
    public function writeWithoutGuard(array $itemIds, int $spokeId, string $actorId, CarbonImmutable $now): void
    {
        foreach ($itemIds as $itemId) {
            DB::table('work_items')->where('id', $itemId)->update([
                'state' => WorkItemState::Claimed->value,
                'claimant_spoke_id' => $spokeId,
                'claimant_actor' => $actorId,
                'lease_until' => $now->addMinutes(5),
                'claimed_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
