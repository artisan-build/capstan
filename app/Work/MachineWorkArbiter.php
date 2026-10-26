<?php

namespace App\Work;

use App\Enums\SpokeLiveness;
use App\Machine\MachineUsageFreshness;
use App\Machine\MachineWorkRuntime;
use App\Models\MachineUsageProvider;
use App\Models\MachineUsageReport;
use App\Models\MachineUsageWindow;
use App\Models\MachineWorkPresence;
use App\Models\Spoke;
use Carbon\CarbonImmutable;

final readonly class MachineWorkArbiter
{
    public function __construct(
        private MachineWorkRuntime $runtime,
        private MachineUsageFreshness $freshness,
    ) {}

    /** @param list<string> $requiredCapabilities
     * @param  list<string>  $excludedTaskIds
     */
    public function isWinner(
        int $teamId,
        string $role,
        array $requiredCapabilities,
        int $spokeId,
        CarbonImmutable $now,
        bool $allowPreemption = false,
        ?string $userActorAddress = null,
        ?string $ownerActor = null,
        bool $routingPoolWork = true,
        ?int $deviceSpokeId = null,
        array $excludedTaskIds = [],
        bool $ignoreCapacityCeiling = false,
    ): bool {
        /** @var list<array{id: int, full: int, health: int, known: int, remaining: float|null, reset: int|null, count: int, ceiling: int}> $ranked */
        $ranked = [];
        $presences = MachineWorkPresence::query()
            ->where('team_id', $teamId)
            ->where('observed_at', '>', $now->subSeconds(MachineWorkRuntime::PRESENCE_TTL_SECONDS))
            ->with(['spoke.usageReport.providers.windows'])
            ->get();

        foreach ($presences as $presence) {
            $machine = $presence->spoke;
            if (! in_array($role, $presence->roles, true)
                || array_diff($requiredCapabilities, $presence->capabilities) !== []
                || (! $allowPreemption && ! $ignoreCapacityCeiling && $presence->available_capacity < 1)
                || ($userActorAddress !== null && $machine->actor_id !== $userActorAddress)
                || ($userActorAddress === null && ! $routingPoolWork && $machine->actor_id !== $ownerActor)
                || ($deviceSpokeId !== null && $machine->id !== $deviceSpokeId)) {
                continue;
            }

            $setting = $this->runtime->eligibleSetting(
                $teamId,
                $machine->actor_id,
                $machine->id,
                $presence->roles,
                $presence->capabilities,
                $presence->role_providers,
                $now,
            );
            if ($setting === null) {
                continue;
            }

            $count = $this->runtime->authoritativeClaimedCount($machine->id);
            $full = $count >= $setting->concurrency_ceiling;
            if ($full && ! $ignoreCapacityCeiling
                && (! $allowPreemption || resolve(WorkPreemptionService::class)->victim($machine->id, $now, $excludedTaskIds) === null)) {
                continue;
            }
            if (! $ignoreCapacityCeiling && ! $full && $presence->available_capacity < 1) {
                continue;
            }

            $usage = $this->usage($machine, $presence, $role, $now);
            $ranked[] = [
                'id' => $machine->id,
                'full' => $full ? 1 : 0,
                'health' => $machine->probe_status === SpokeLiveness::Green ? 0 : 1,
                'known' => $usage['known'],
                'remaining' => $usage['remaining'],
                'reset' => $usage['reset'],
                'count' => $count,
                'ceiling' => $setting->concurrency_ceiling,
            ];
        }

        usort($ranked, $this->compare(...));

        return ($ranked[0]['id'] ?? null) === $spokeId;
    }

    /**
     * @return array{known: int, remaining: float|null, reset: int|null}
     */
    private function usage(Spoke $machine, MachineWorkPresence $presence, string $role, CarbonImmutable $now): array
    {
        $providerFamily = $presence->role_providers[$role] ?? null;
        $report = $machine->usageReport;
        if ($providerFamily === null || ! $report instanceof MachineUsageReport) {
            return ['known' => 1, 'remaining' => null, 'reset' => null];
        }

        $provider = $report->providers->first(
            fn (MachineUsageProvider $candidate): bool => hash_equals($providerFamily, $candidate->provider),
        );
        if (! $provider instanceof MachineUsageProvider
            || ! $this->freshness->providerIsFresh($report, $provider, $now)
            || $provider->windows->isEmpty()) {
            return ['known' => 1, 'remaining' => null, 'reset' => null];
        }

        $resets = $provider->windows->pluck('resets_at')->filter()->map->getTimestamp();

        return [
            'known' => 0,
            'remaining' => $provider->windows->min(fn (MachineUsageWindow $window): float => (float) $window->remaining_percent),
            'reset' => $resets->isEmpty() ? null : (int) $resets->min(),
        ];
    }

    /** @param array{id: int, full: int, health: int, known: int, remaining: float|null, reset: int|null, count: int, ceiling: int} $left
     * @param  array{id: int, full: int, health: int, known: int, remaining: float|null, reset: int|null, count: int, ceiling: int}  $right
     */
    private function compare(array $left, array $right): int
    {
        foreach (['full', 'health', 'known'] as $key) {
            if ($left[$key] !== $right[$key]) {
                return $left[$key] <=> $right[$key];
            }
        }

        if ($left['known'] === 0) {
            if ($left['remaining'] !== $right['remaining']) {
                return $right['remaining'] <=> $left['remaining'];
            }
            if ($left['reset'] !== $right['reset']) {
                if ($left['reset'] === null) {
                    return 1;
                }
                if ($right['reset'] === null) {
                    return -1;
                }

                return $left['reset'] <=> $right['reset'];
            }
        }

        $leftLoad = $left['count'] * $right['ceiling'];
        $rightLoad = $right['count'] * $left['ceiling'];
        if ($leftLoad !== $rightLoad) {
            return $leftLoad <=> $rightLoad;
        }

        return $left['id'] <=> $right['id'];
    }
}
