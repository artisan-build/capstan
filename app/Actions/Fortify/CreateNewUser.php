<?php

namespace App\Actions\Fortify;

use App\Actions\RegisterFirstOwner;
use App\Actions\RegisterInvitedUser;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (User::query()->exists()) {
            Validator::make($input, [
                'invitation_code' => ['required', 'string', 'max:255', 'exists:invitations,code'],
            ])->after(function ($validator) use ($input) {
                if ($validator->errors()->has('invitation_code')) {
                    return;
                }

                $code = $input['invitation_code'] ?? null;

                if (! is_string($code)) {
                    $validator->errors()->add('invitation_code', __('A valid invitation code is required.'));

                    return;
                }

                $invitation = Invitation::query()->where('code', $code)->unused()->first();

                if (! $invitation) {
                    $validator->errors()->add('invitation_code', __('A valid invitation code is required.'));

                    return;
                }

                if ($invitation->isExpired()) {
                    $validator->errors()->add('invitation_code', __('This invitation has expired.'));
                }
            })->validate();
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'invitation_code' => ['nullable', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ])->validate();

        $registration = [
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ];

        if (! User::query()->exists()) {
            return app(RegisterFirstOwner::class)->handle($registration);
        }

        return app(RegisterInvitedUser::class)->handle([
            ...$registration,
            'invitation_code' => $input['invitation_code'] ?? null,
        ]);
    }
}
