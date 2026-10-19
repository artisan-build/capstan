<?php

namespace App\Machine;

use App\Models\MachineTeamWorkSetting;
use App\Models\MachineWorkSettingChange;
use App\Models\Spoke;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ApplyPendingMachineProfile
{
    public function apply(Spoke $spoke, string $actorId, string $credentialId): bool
    {
        $install = DB::table('postmaster_installs')
            ->join('credential_authorizations', 'credential_authorizations.id', '=', 'postmaster_installs.credential_authorization_id')
            ->where('credential_authorizations.issued_credential_id', $credentialId)
            ->where('credential_authorizations.initiating_user_id', $actorId)
            ->where('credential_authorizations.app_purpose', 'capstan.machine')
            ->where('postmaster_installs.actor_id', $actorId)
            ->whereNotNull('postmaster_installs.used_at')
            ->whereNull('postmaster_installs.applied_at')
            ->select('postmaster_installs.*')
            ->lockForUpdate()
            ->first();

        if ($install === null) {
            return false;
        }

        $user = User::query()->whereKey($actorId)->lockForUpdate()->first();
        $team = Team::query()->whereKey($install->team_id)->lockForUpdate()->first();
        if (! $user instanceof User
            || ! DomainIdentityContext::forUser($user, InstallationAuthority::current())->canUseProduct()
            || ! $team instanceof Team) {
            throw new RuntimeException('The pending machine profile is no longer authorized.');
        }

        $profile = [
            'roles' => $this->jsonList($install->work_roles),
            'capabilities' => $this->jsonList($install->work_capabilities),
            'role_providers' => $this->jsonMap($install->work_role_providers),
            'concurrency_ceiling' => (int) $install->work_concurrency_ceiling,
        ];
        MachineRecordBounds::workProfile($profile);

        $setting = MachineTeamWorkSetting::query()
            ->where('spoke_id', $spoke->id)
            ->where('team_id', $team->id)
            ->lockForUpdate()
            ->first();
        $old = $setting === null ? null : [
            'enabled' => $setting->enabled,
            'roles' => $setting->roles,
            'capabilities' => $setting->capabilities,
            'role_providers' => $setting->role_providers,
            'concurrency_ceiling' => $setting->concurrency_ceiling,
        ];
        $values = [
            'enabled' => (bool) $install->work_enabled,
            ...$profile,
            'updated_by_actor' => $actorId,
        ];

        if ($setting === null) {
            $setting = new MachineTeamWorkSetting([
                'spoke_id' => $spoke->id,
                'team_id' => $team->id,
                ...$values,
            ]);
        } else {
            $setting->fill($values);
        }
        $setting->save();

        MachineWorkSettingChange::query()->create([
            'spoke_id' => $spoke->id,
            'team_id' => $team->id,
            'old_enabled' => $old['enabled'] ?? null,
            'old_roles' => $old['roles'] ?? null,
            'old_capabilities' => $old['capabilities'] ?? null,
            'old_role_providers' => $old['role_providers'] ?? null,
            'old_concurrency_ceiling' => $old['concurrency_ceiling'] ?? null,
            'new_enabled' => $values['enabled'],
            'new_roles' => $values['roles'],
            'new_capabilities' => $values['capabilities'],
            'new_role_providers' => $values['role_providers'],
            'new_concurrency_ceiling' => $values['concurrency_ceiling'],
            'updated_by_actor' => $actorId,
            'occurred_at' => now(),
        ]);

        DB::table('postmaster_installs')->where('id', $install->id)->update([
            'script' => '',
            'team_id' => null,
            'work_enabled' => null,
            'work_roles' => null,
            'work_capabilities' => null,
            'work_role_providers' => null,
            'work_concurrency_ceiling' => null,
            'applied_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        $decoded = $this->decode($value);
        if (! array_is_list($decoded)) {
            throw new RuntimeException('The pending machine profile is invalid.');
        }

        return $decoded;
    }

    /** @return array<string, string> */
    private function jsonMap(mixed $value): array
    {
        $decoded = $this->decode($value);
        if (array_is_list($decoded) && $decoded !== []) {
            throw new RuntimeException('The pending machine profile is invalid.');
        }

        return $decoded;
    }

    /** @return array<array-key, mixed> */
    private function decode(mixed $value): array
    {
        if (! is_string($value) || strlen($value) > 8192) {
            throw new RuntimeException('The pending machine profile is invalid.');
        }
        try {
            $decoded = json_decode($value, true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('The pending machine profile is invalid.');
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('The pending machine profile is invalid.');
        }

        return $decoded;
    }
}
