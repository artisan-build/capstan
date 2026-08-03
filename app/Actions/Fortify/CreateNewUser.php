<?php

namespace App\Actions\Fortify;

use App\Actions\RegisterFirstOwner;
use App\Actions\RegisterInvitedUser;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
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
        Validator::make($input, [
            ...$this->profileRules(),
            'invitation_code' => ['nullable', 'string'],
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
