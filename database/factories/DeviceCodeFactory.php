<?php

namespace Database\Factories;

use App\Enums\DeviceCodeStatus;
use App\Models\DeviceCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeviceCode> */
class DeviceCodeFactory extends Factory
{
    protected $model = DeviceCode::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'device_code_hash' => DeviceCode::hash(fake()->sha256()),
            'user_code' => DeviceCode::generateUserCode(),
            'label' => null,
            'status' => DeviceCodeStatus::Pending,
            'interval' => DeviceCode::POLL_INTERVAL_SECONDS,
            'expires_at' => now()->addSeconds(DeviceCode::LIFETIME_SECONDS),
            'last_polled_at' => null,
        ];
    }
}
