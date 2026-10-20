<?php

namespace App\Machine;

use App\Models\MachineTeamWorkSetting;
use App\Models\MachineWorkSettingChange;
use App\Models\Spoke;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MachineWorkProfiles
{
    /**
     * @param  array{
     *     enabled: bool,
     *     roles: list<string>,
     *     capabilities: list<string>,
     *     role_providers: array<string, string>,
     *     concurrency_ceiling: int
     * }  $profile
     */
    public function put(Spoke $machine, Team $team, User $actor, array $profile, CarbonImmutable $now): bool
    {
        $this->authorize($machine, $team, $actor);
        $profile = $this->normalize($profile);
        MachineRecordBounds::workProfile($profile);

        return DB::transaction(function () use ($machine, $team, $actor, $profile, $now): bool {
            $lockedMachine = Spoke::query()->whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $lockedTeam = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            $setting = MachineTeamWorkSetting::query()
                ->where('spoke_id', $lockedMachine->id)
                ->where('team_id', $lockedTeam->id)
                ->lockForUpdate()
                ->first();
            $this->authorize($lockedMachine, $lockedTeam, $lockedActor, $setting !== null, lockMembership: true);
            $old = $setting === null ? null : $this->values($setting);

            if ($old === $profile) {
                return false;
            }

            $setting ??= new MachineTeamWorkSetting([
                'spoke_id' => $lockedMachine->id,
                'team_id' => $lockedTeam->id,
            ]);
            $setting->fill([...$profile, 'updated_by_actor' => (string) $actor->getKey()])->save();

            MachineWorkSettingChange::query()->create([
                'spoke_id' => $lockedMachine->id,
                'team_id' => $lockedTeam->id,
                'old_enabled' => $old['enabled'] ?? null,
                'old_roles' => $old['roles'] ?? null,
                'old_capabilities' => $old['capabilities'] ?? null,
                'old_role_providers' => $old['role_providers'] ?? null,
                'old_concurrency_ceiling' => $old['concurrency_ceiling'] ?? null,
                'new_enabled' => $profile['enabled'],
                'new_roles' => $profile['roles'],
                'new_capabilities' => $profile['capabilities'],
                'new_role_providers' => $profile['role_providers'],
                'new_concurrency_ceiling' => $profile['concurrency_ceiling'],
                'updated_by_actor' => (string) $actor->getKey(),
                'occurred_at' => $now,
            ]);

            return true;
        });
    }

    public function authorize(
        Spoke $machine,
        Team $team,
        User $actor,
        ?bool $hasExistingSetting = null,
        bool $lockMembership = false,
    ): void {
        $actorId = (string) $actor->getKey();
        $admitted = $actor->status === 'active'
            && ! OffboardedSubject::userIsOffboarded($actorId)
            && DomainIdentityContext::forUser($actor, InstallationAuthority::current())->canUseProduct();
        $membership = DB::table('team_user')
            ->where('user_id', $actor->getKey())
            ->where('team_id', $team->id);
        $belongsToTeam = $lockMembership
            ? $membership->lockForUpdate()->first(['user_id']) !== null
            : $membership->exists();
        $ownsMachine = hash_equals($machine->actor_id, $actorId);
        $hasExistingSetting ??= MachineTeamWorkSetting::query()
            ->where('spoke_id', $machine->id)
            ->where('team_id', $team->id)
            ->exists();
        $teamManagerUpdatingExisting = RolePolicy::canManageMembers($actor->role) && $hasExistingSetting;

        if (! $admitted || ! $belongsToTeam || (! $ownsMachine && ! $teamManagerUpdatingExisting)) {
            throw new AuthorizationException('Only a target-team machine owner or manager may change its Work profile.');
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{enabled: bool, roles: list<string>, capabilities: list<string>, role_providers: array<string, string>, concurrency_ceiling: int}
     */
    private function normalize(array $profile): array
    {
        if (! isset($profile['enabled']) || ! is_bool($profile['enabled'])
            || ! isset($profile['roles']) || ! is_array($profile['roles'])
            || ! isset($profile['capabilities']) || ! is_array($profile['capabilities'])
            || ! isset($profile['role_providers']) || ! is_array($profile['role_providers'])
            || ! isset($profile['concurrency_ceiling']) || ! is_int($profile['concurrency_ceiling'])) {
            throw new InvalidArgumentException('The machine Work profile is invalid.');
        }

        $roles = array_values($profile['roles']);
        $capabilities = array_values($profile['capabilities']);
        $providers = $profile['role_providers'];
        /** @var list<string> $roles */
        /** @var list<string> $capabilities */
        /** @var array<string, string> $providers */
        sort($roles, SORT_STRING);
        sort($capabilities, SORT_STRING);
        ksort($providers, SORT_STRING);

        return [
            'enabled' => $profile['enabled'],
            'roles' => $roles,
            'capabilities' => $capabilities,
            'role_providers' => $providers,
            'concurrency_ceiling' => $profile['concurrency_ceiling'],
        ];
    }

    /** @return array{enabled: bool, roles: list<string>, capabilities: list<string>, role_providers: array<string, string>, concurrency_ceiling: int} */
    private function values(MachineTeamWorkSetting $setting): array
    {
        return $this->normalize([
            'enabled' => $setting->enabled,
            'roles' => $setting->roles,
            'capabilities' => $setting->capabilities,
            'role_providers' => $setting->role_providers,
            'concurrency_ceiling' => $setting->concurrency_ceiling,
        ]);
    }
}
