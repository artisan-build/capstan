<?php

namespace App\Actions;

use App\Enums\OrgRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ChangeOrgRole
{
    public function __construct(private readonly EnsureAnotherOwnerRemains $ensureAnotherOwnerRemains) {}

    public function handle(User $actor, User $target, OrgRole $role): User
    {
        Gate::forUser($actor)->authorize('updateOrgRole', [$target, $role]);

        $updatedTarget = DB::transaction(function () use ($target, $role): User {
            $freshTarget = User::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($freshTarget->org_role === OrgRole::Owner && $role !== OrgRole::Owner) {
                $this->ensureAnotherOwnerRemains->handle($freshTarget);
            }

            $freshTarget->forceFill(['org_role' => $role])->save();

            return $freshTarget;
        });

        return $updatedTarget->refresh();
    }
}
