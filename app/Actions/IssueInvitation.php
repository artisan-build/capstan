<?php

namespace App\Actions;

use App\Enums\OrgRole;
use App\Models\Invitation;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class IssueInvitation
{
    public function handle(User $issuer, ?string $email = null, OrgRole $role = OrgRole::Member, ?DateTimeInterface $expiresAt = null): Invitation
    {
        Gate::forUser($issuer)->authorize('create', Invitation::class);

        if ($role === OrgRole::Owner && $issuer->org_role !== OrgRole::Owner) {
            throw new AuthorizationException;
        }

        return Invitation::create([
            'code' => Str::random(32),
            'email' => $email,
            'role' => $role,
            'issued_by' => $issuer->id,
            'expires_at' => $expiresAt ?? now()->addDays(14),
        ]);
    }
}
