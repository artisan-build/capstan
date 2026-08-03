<?php

namespace App\Actions;

use App\Enums\OrgRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ChangeOrgRole
{
    public function handle(User $actor, User $target, OrgRole $role): User
    {
        Gate::forUser($actor)->authorize('updateOrgRole', [$target, $role]);

        $target->forceFill(['org_role' => $role])->save();

        return $target->refresh();
    }
}
