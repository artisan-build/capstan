<?php

namespace Database\Factories;

use App\Enums\OrgRole;
use App\Models\Invitation;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::random(32),
            'email' => null,
            'role' => OrgRole::Member,
            'issued_by' => User::factory(),
            'used_by' => null,
            'used_at' => null,
            'expires_at' => now()->addDays(14),
        ];
    }

    public function email(string $email): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => $email,
        ]);
    }

    public function role(OrgRole $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role,
        ]);
    }

    public function expiresAt(?DateTimeInterface $expiresAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => $expiresAt,
        ]);
    }

    public function expired(): static
    {
        return $this->expiresAt(now()->subMinute());
    }
}
