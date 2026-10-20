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
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MachineWorkProfiles
{
    /**
     * @return array{
     *     machines: Collection<int, Spoke>,
     *     owners: Collection<string, User>,
     *     permissions: array<int, bool>,
     *     truncated: bool
     * }
     */
    public function projectionCandidates(Team $team, User $actor, bool $actorAdmitted, int $limit): array
    {
        /** @var Collection<int, Spoke> $selected */
        $selected = collect();
        /** @var Collection<string, User> $selectedOwners */
        $selectedOwners = collect();
        /** @var array<int, bool> $selectedPermissions */
        $selectedPermissions = [];
        $afterId = 0;
        $batchSize = $limit + 1;

        do {
            /** @var Collection<int, Spoke> $batch */
            $batch = Spoke::query()
                ->where('credential_backed', true)
                ->whereNull('retired_at')
                ->where('id', '>', $afterId)
                ->with(['workSettings' => fn ($query) => $query->where('team_id', $team->id)])
                ->orderBy('id')
                ->limit($batchSize)
                ->get();
            if ($batch->isEmpty()) {
                break;
            }

            $afterId = (int) $batch->last()->id;
            $owners = $this->owners($batch);
            $decisions = $this->decisions($team, $actor, $batch, $owners, $actorAdmitted);

            foreach ($batch as $machine) {
                if (! ($decisions['candidates'][$machine->id] ?? false)) {
                    continue;
                }

                $selected->push($machine);
                $selectedPermissions[$machine->id] = $decisions['permissions'][$machine->id] ?? false;
                $owner = $owners->get($machine->actor_id);
                if ($owner instanceof User) {
                    $selectedOwners->put($machine->actor_id, $owner);
                }

                if ($selected->count() > $limit) {
                    break 2;
                }
            }
        } while ($batch->count() === $batchSize);

        $machines = $selected->take($limit)->values();
        $machineIds = $machines->pluck('id')->flip()->all();

        return [
            'machines' => $machines,
            'owners' => $selectedOwners,
            'permissions' => array_intersect_key($selectedPermissions, $machineIds),
            'truncated' => $selected->count() > $limit,
        ];
    }

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
            $lockedOwner = hash_equals($lockedMachine->actor_id, (string) $lockedActor->getKey())
                ? $lockedActor
                : User::query()->whereKey($lockedMachine->actor_id)->lockForUpdate()->first();

            $setting = MachineTeamWorkSetting::query()
                ->where('spoke_id', $lockedMachine->id)
                ->where('team_id', $lockedTeam->id)
                ->lockForUpdate()
                ->first();
            $this->authorize($lockedMachine, $lockedTeam, $lockedActor, $setting !== null, true, $lockedOwner);
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
        ?User $owner = null,
    ): void {
        $actorId = (string) $actor->getKey();
        $owner ??= hash_equals($machine->actor_id, $actorId)
            ? $actor
            : $this->owner($machine, $lockMembership);
        $actorBelongsToTeam = $this->belongsToTeam($actor, $team, $lockMembership);
        $ownerBelongsToTeam = $owner instanceof User
            && (hash_equals((string) $owner->getKey(), $actorId)
                ? $actorBelongsToTeam
                : $this->belongsToTeam($owner, $team, $lockMembership));
        $hasExistingSetting ??= MachineTeamWorkSetting::query()
            ->where('spoke_id', $machine->id)
            ->where('team_id', $team->id)
            ->exists();
        $ownerAnchorsFirstProfile = $this->ownerAnchorsFirstProfile(
            $machine,
            $owner,
            $owner instanceof User && $this->isAdmitted($owner),
            $ownerBelongsToTeam,
        );

        if (! $this->permitted(
            $machine,
            $actor,
            $this->isAdmitted($actor),
            $actorBelongsToTeam,
            $hasExistingSetting,
            $ownerAnchorsFirstProfile,
        )) {
            throw new AuthorizationException('Only a target-team machine owner or manager may change its Work profile.');
        }
    }

    /**
     * @param  Collection<int, Spoke>  $machines
     * @param  Collection<string, User>  $owners
     * @return array{permissions: array<int, bool>, candidates: array<int, bool>}
     */
    private function decisions(Team $team, User $actor, Collection $machines, Collection $owners, bool $actorAdmitted): array
    {
        $ownerIds = $machines->pluck('actor_id')
            ->filter(fn (mixed $id): bool => is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) === 1)
            ->unique()->values();
        $actorId = (string) $actor->getKey();
        $membershipUserIds = array_values(array_unique([...$ownerIds->all(), $actorId]));
        $memberIds = DB::table('team_user')
            ->where('team_id', $team->id)
            ->whereIn('user_id', $membershipUserIds)
            ->pluck('user_id')->map(fn (mixed $id): string => (string) $id)->flip();
        /** @var array<string, bool> $ownerAdmission */
        $ownerAdmission = [];

        /** @var array<int, bool> $permissions */
        $permissions = [];
        /** @var array<int, bool> $candidates */
        $candidates = [];

        foreach ($machines as $machine) {
            $owner = $owners->get($machine->actor_id);
            $hasExistingSetting = $machine->relationLoaded('workSettings')
                ? $machine->workSettings->contains('team_id', $team->id)
                : MachineTeamWorkSetting::query()->where('spoke_id', $machine->id)->where('team_id', $team->id)->exists();
            $ownerAdmitted = $owner instanceof User
                ? ($ownerAdmission[$machine->actor_id] ??= hash_equals($machine->actor_id, $actorId)
                    ? $actorAdmitted
                    : $this->isAdmitted($owner))
                : false;
            $ownerBelongsToTeam = $owner instanceof User && $memberIds->has((string) $owner->getKey());
            $ownerAnchorsFirstProfile = $this->ownerAnchorsFirstProfile(
                $machine,
                $owner,
                $ownerAdmitted,
                $ownerBelongsToTeam,
            );

            $candidates[$machine->id] = $this->isPhysicalAndCurrent($machine)
                && ($hasExistingSetting || $ownerAnchorsFirstProfile);
            $permissions[$machine->id] = $this->permitted(
                $machine,
                $actor,
                $actorAdmitted,
                $memberIds->has($actorId),
                $hasExistingSetting,
                $ownerAnchorsFirstProfile,
            );
        }

        return ['permissions' => $permissions, 'candidates' => $candidates];
    }

    private function permitted(
        Spoke $machine,
        User $actor,
        bool $actorAdmitted,
        bool $actorBelongsToTeam,
        bool $hasExistingSetting,
        bool $ownerAnchorsFirstProfile,
    ): bool {
        if (! $this->isPhysicalAndCurrent($machine)
            || $machine->orphaned_at !== null
            || ! $actorAdmitted
            || ! $actorBelongsToTeam) {
            return false;
        }

        if (hash_equals($machine->actor_id, (string) $actor->getKey())) {
            return true;
        }

        if (! RolePolicy::canManageMembers($actor->role)) {
            return false;
        }

        return $hasExistingSetting || $ownerAnchorsFirstProfile;
    }

    private function ownerAnchorsFirstProfile(Spoke $machine, ?User $owner, bool $ownerAdmitted, bool $ownerBelongsToTeam): bool
    {
        return $machine->orphaned_at === null
            && $owner instanceof User
            && $ownerAdmitted
            && $ownerBelongsToTeam;
    }

    private function isPhysicalAndCurrent(Spoke $machine): bool
    {
        return $machine->credential_backed && $machine->retired_at === null;
    }

    public function isAdmitted(User $actor): bool
    {
        $actorId = (string) $actor->getKey();

        return $actor->status === 'active'
            && RolePolicy::canUseProduct($actor->role)
            && ! OffboardedSubject::subjectIsOffboarded(new Subject(SubjectType::UserPrincipal, 'capstan-user:'.$actorId))
            && ! OffboardedSubject::userIsOffboarded($actorId)
            && DomainIdentityContext::forUser($actor, InstallationAuthority::current())->canUseProduct();
    }

    private function belongsToTeam(User $actor, Team $team, bool $lock): bool
    {
        $membership = DB::table('team_user')
            ->where('user_id', $actor->getKey())
            ->where('team_id', $team->id);

        return $lock
            ? $membership->lockForUpdate()->first(['user_id']) !== null
            : $membership->exists();
    }

    private function owner(Spoke $machine, bool $lock): ?User
    {
        $query = User::query()->whereKey($machine->actor_id);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /**
     * @param  Collection<int, Spoke>  $machines
     * @return Collection<string, User>
     */
    private function owners(Collection $machines): Collection
    {
        $ids = $machines->pluck('actor_id')
            ->filter(fn (mixed $id): bool => is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) === 1)
            ->unique()->values()->all();

        return User::query()->whereIn('id', $ids)->get()
            ->keyBy(fn (User $user): string => (string) $user->getKey());
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
