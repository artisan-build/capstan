<?php

namespace Database\Factories;

use App\Models\AuthorizationCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuthorizationCode> */
class AuthorizationCodeFactory extends Factory
{
    protected $model = AuthorizationCode::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code_hash' => AuthorizationCode::hash(fake()->sha256()),
            'user_id' => User::factory(),
            'label' => null,
            'redirect_uri' => 'http://127.0.0.1:49152/callback',
            'expires_at' => now()->addSeconds(AuthorizationCode::LIFETIME_SECONDS),
            'consumed_at' => null,
        ];
    }
}
