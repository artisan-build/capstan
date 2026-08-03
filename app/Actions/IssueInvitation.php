<?php

namespace App\Actions;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class IssueInvitation
{
    public function handle(User $issuer): Invitation
    {
        Gate::forUser($issuer)->authorize('create', Invitation::class);

        return Invitation::create([
            'code' => Str::random(32),
            'issued_by' => $issuer->id,
        ]);
    }
}
