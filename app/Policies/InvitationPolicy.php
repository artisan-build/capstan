<?php

namespace App\Policies;

use App\Models\User;

class InvitationPolicy
{
    public function create(User $user): bool
    {
        return $user->canIssueInvitations();
    }
}
