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

    public function remove(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if (! $actor->canIssueInvitations()) {
            return false;
        }

        return $actor->org_role === OrgRole::Owner || $target->org_role !== OrgRole::Owner;
    }
}
