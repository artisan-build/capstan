<?php

namespace App\Machine;

use App\Enums\SpokeLiveness;
use App\Models\MachineTeamWorkSetting;
use App\Models\MachineWorkPresence;
use App\Models\Spoke;
use App\Models\WorkItem;
use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MachineWorkRuntime
{
    public const int PRESENCE_TTL_SECONDS = 180;

    /**
     * @param  non-empty-list<string>  $roles
     * @param  list<string>  $capabilities
     * @param  array<string, string>  $roleProviders
     */
    public function record(
        int $teamId,
        string $actorId,
        string $credentialId,
        array $roles,
        array $capabilities,
        array $roleProviders,
        int $requestedCapacity,
        int $reportedClaimedCount,
        CarbonImmutable $now,
        bool $combinedCapacity = false,
    ): ?MachineWorkPresence {
        if ($requestedCapacity < 0 || $requestedCapacity > MachineRecordBounds::MAX_PROFILE_ENTRIES) {
            throw new InvalidArgumentException('The requested capacity is invalid.');
        }
        if ($reportedClaimedCount < 0 || $reportedClaimedCount > MachineRecordBounds::MAX_PROFILE_ENTRIES) {
            throw new InvalidArgumentException('The reported claimed item count is invalid.');
        }

        return DB::transaction(function () use ($teamId, $actorId, $credentialId, $roles, $capabilities, $roleProviders, $requestedCapacity, $now, $combinedCapacity): ?MachineWorkPresence {
            $machine = Spoke::query()->workEligible()
                ->where('actor_id', $actorId)
                ->where('credential_id', $credentialId)
                ->where('credential_backed', true)
                ->lockForUpdate()
                ->first();
            if (! $machine instanceof Spoke) {
                return null;
            }

            $setting = $this->eligibleSetting($teamId, $actorId, $machine->id, $roles, $capabilities, $roleProviders, $now, true, true);
            if (! $setting instanceof MachineTeamWorkSetting) {
                return null;
            }

            $current = $this->authoritativeClaimedCount($machine->id, $combinedCapacity);
            $presence = MachineWorkPresence::query()
                ->where('spoke_id', $machine->id)
                ->where('team_id', $teamId)
                ->lockForUpdate()
                ->first() ?? new MachineWorkPresence(['spoke_id' => $machine->id, 'team_id' => $teamId]);
            $presence->fill([
                'roles' => $this->sorted($roles),
                'capabilities' => $this->sorted($capabilities),
                'role_providers' => $this->sortedMap($roleProviders),
                'current_claimed_item_count' => min($current, $setting->concurrency_ceiling),
                'available_capacity' => min($requestedCapacity, max(0, $setting->concurrency_ceiling - $current)),
                'concurrency_ceiling' => $setting->concurrency_ceiling,
                'observed_at' => $now,
            ])->save();

            return $presence;
        });
    }

    public function refreshCount(MachineWorkPresence $presence, bool $combinedCapacity = false): void
    {
        $setting = MachineTeamWorkSetting::query()
            ->where('spoke_id', $presence->spoke_id)
            ->where('team_id', $presence->team_id)
            ->first();
        if (! $setting instanceof MachineTeamWorkSetting) {
            return;
        }

        $current = min($this->authoritativeClaimedCount($presence->spoke_id, $combinedCapacity), $setting->concurrency_ceiling);
        $newlyClaimed = max(0, $current - $presence->current_claimed_item_count);
        $presence->forceFill([
            'current_claimed_item_count' => $current,
            'available_capacity' => max(0, min($presence->available_capacity - $newlyClaimed, $setting->concurrency_ceiling - $current)),
            'concurrency_ceiling' => $setting->concurrency_ceiling,
        ])->save();
    }

    /**
     * @param  non-empty-list<string>  $roles
     * @param  list<string>  $capabilities
     * @param  array<string, string>  $roleProviders
     */
    public function eligibleSetting(
        int $teamId,
        string $actorId,
        int $spokeId,
        array $roles,
        array $capabilities,
        array $roleProviders,
        CarbonImmutable $now,
        bool $lock = false,
        bool $rejectRuntimeMismatch = false,
    ): ?MachineTeamWorkSetting {
        MachineRecordBounds::workProfile([
            'roles' => $roles,
            'capabilities' => $capabilities,
            'role_providers' => $roleProviders,
            'concurrency_ceiling' => 1,
        ]);

        $machineQuery = Spoke::query()->workEligible()->whereKey($spokeId)->where('actor_id', $actorId);
        $machine = $this->locked($machineQuery, $lock)->first();
        if (! $machine instanceof Spoke || ! $this->isActive($machine, $now) || ! $this->ownerIsActive($actorId)) {
            return null;
        }

        $settingQuery = MachineTeamWorkSetting::query()
            ->where('spoke_id', $spokeId)
            ->where('team_id', $teamId)
            ->where('enabled', true);
        $setting = $this->locked($settingQuery, $lock)->first();
        if (! $setting instanceof MachineTeamWorkSetting) {
            return null;
        }
        if (array_diff($roles, $setting->roles) !== []
            || array_diff($capabilities, $setting->capabilities) !== []) {
            if ($rejectRuntimeMismatch) {
                throw new InvalidArgumentException('Runtime roles and capabilities may only narrow the stored Work profile.');
            }

            return null;
        }

        foreach ($roleProviders as $role => $provider) {
            if (! isset($setting->role_providers[$role]) || ! hash_equals($setting->role_providers[$role], $provider)) {
                if ($rejectRuntimeMismatch) {
                    throw new InvalidArgumentException('Runtime role providers must match the stored Work profile.');
                }

                return null;
            }
        }

        return $setting;
    }

    public function authoritativeClaimedCount(int $spokeId, bool $includeOrchestrators = false): int
    {
        return WorkItem::query()
            ->where('claimant_spoke_id', $spokeId)
            ->whereIn('state', ['claimed', 'delivered', 'routed', 'awaiting_verification'])
            ->unless($includeOrchestrators, fn (Builder $query) => $query->where('role', '!=', WorkItem::ROLE_ORCHESTRATOR))
            ->count();
    }

    public function authoritativeOrchestratorCount(int $spokeId): int
    {
        return WorkItem::query()
            ->where('claimant_spoke_id', $spokeId)
            ->whereIn('state', ['claimed', 'delivered', 'routed', 'awaiting_verification'])
            ->where('role', WorkItem::ROLE_ORCHESTRATOR)
            ->count();
    }

    public function isFresh(MachineWorkPresence $presence, CarbonImmutable $now): bool
    {
        return $presence->observed_at->greaterThan($now->subSeconds(self::PRESENCE_TTL_SECONDS));
    }

    private function isActive(Spoke $machine, CarbonImmutable $now): bool
    {
        return $machine->inactive_at === null
            && $machine->probe_status !== SpokeLiveness::Red
            && $machine->last_polled_at !== null
            && $machine->last_polled_at->greaterThan($now->subSeconds(300));
    }

    private function ownerIsActive(string $actorId): bool
    {
        $owner = User::query()->find($actorId);

        return $owner instanceof User
            && $owner->status === 'active'
            && ! OffboardedSubject::userIsOffboarded($actorId)
            && DomainIdentityContext::forUser($owner, InstallationAuthority::current())->canUseProduct();
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function locked(Builder $query, bool $lock): Builder
    {
        return $lock ? $query->lockForUpdate() : $query;
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }

    /** @param array<string, string> $values
     * @return array<string, string>
     */
    private function sortedMap(array $values): array
    {
        ksort($values, SORT_STRING);

        return $values;
    }
}
