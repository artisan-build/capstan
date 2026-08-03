<?php

namespace App\Actions;

use App\Enums\OrgRole;
use App\Models\User;
use Illuminate\Database\QueryException;
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

                DB::table('first_owner_claims')->insert([
                    'name' => 'first_owner',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return User::create([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                    'org_role' => OrgRole::Owner,
                ]);
            });
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'email' => __('The first owner has already been claimed.'),
            ]);
        }
    }
}
