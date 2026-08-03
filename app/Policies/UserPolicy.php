<?php

namespace App\Policies;

use App\Enums\OrgRole;
use App\Models\User;

class UserPolicy
{
    public function updateOrgRole(User $actor, User $target, OrgRole $role): bool
    {
        return $actor->canChangeOrgRoleTo($target, $role);
    }
}
