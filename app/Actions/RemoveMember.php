<?php

namespace App\Actions;

use App\Enums\OrgRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RemoveMember
{
    public function __construct(private readonly EnsureAnotherOwnerRemains $ensureAnotherOwnerRemains) {}

    public function handle(User $actor, User $target): void
    {
        Gate::forUser($actor)->authorize('remove', $target);

        DB::transaction(function () use ($target): void {
            $freshTarget = User::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($freshTarget->org_role === OrgRole::Owner) {
                $this->ensureAnotherOwnerRemains->handle($freshTarget);
            }

            $freshTarget->delete();
        });
    }
}
