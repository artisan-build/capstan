<?php

namespace App\Actions;

use App\Enums\OrgRole;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterFirstOwner
{
    /**
     * @param  array{name: string, email: string, password: string}  $input
     */
    public function handle(array $input): User
    {
        try {
            return DB::transaction(function () use ($input) {
                $now = now();

                DB::delete(
                    'delete from first_owner_claims where name = ? and not exists (select 1 from users)',
                    ['first_owner'],
                );

                DB::table('first_owner_claims')->insert([
                    'name' => 'first_owner',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return User::forceCreate([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                    'org_role' => OrgRole::Owner,
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => __('The first owner has already been claimed.'),
            ]);
        }
    }
}
