<?php

namespace App\Actions;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RevokeInvitation
{
    public function handle(User $actor, Invitation $invitation): void
    {
        Gate::forUser($actor)->authorize('delete', $invitation);

        $invitation->delete();
    }
}
