<?php

namespace App\Actions;

use App\Enums\OrgRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class EnsureAnotherOwnerRemains
{
    public function handle(User $target): void
    {
        $anotherOwnerExists = User::query()
            ->where('org_role', OrgRole::Owner)
            ->whereKeyNot($target->id)
            ->lockForUpdate()
            ->exists();

        if (! $anotherOwnerExists) {
            throw ValidationException::withMessages([
                'org_role' => __('The organization must always have at least one owner.'),
            ]);
        }
    }
}
