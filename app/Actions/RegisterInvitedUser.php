<?php

namespace App\Actions;

use App\Enums\OrgRole;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterInvitedUser
{
    /**
     * @param  array{name: string, email: string, password: string, invitation_code?: string|null}  $input
     */
    public function handle(array $input): User
    {
        return DB::transaction(function () use ($input) {
            $invitation = Invitation::query()
                ->where('code', $input['invitation_code'] ?? '')
                ->unused()
                ->first();

            if (! $invitation) {
                throw ValidationException::withMessages([
                    'invitation_code' => __('A valid invitation code is required.'),
                ]);
            }

            $user = User::forceCreate([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'org_role' => OrgRole::Member,
            ]);

            $claimed = Invitation::query()
                ->whereKey($invitation->id)
                ->whereNull('used_at')
                ->update([
                    'used_by' => $user->id,
                    'used_at' => now(),
                ]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages([
                    'invitation_code' => __('This invitation code has already been used.'),
                ]);
            }

            return $user;
        });
    }
}
