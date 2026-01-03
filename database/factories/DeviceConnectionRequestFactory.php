<?php

namespace Database\Factories;

use App\Models\DeviceConnectionRequest;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeviceConnectionRequest>
 */
class DeviceConnectionRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mobile_number' => $this->faker->phoneNumber,
            'device_fingerprint' => $this->faker->unique()->sha256,
            'device_info' => [
                'model' => $this->faker->word,
                'os_version' => $this->faker->semver,
                'app_version' => $this->faker->semver,
            ],
            'status' => 'pending',
            'farm_id' => null,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_reason' => null,
        ];
    }

    /**
     * Indicate that the request is approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'farm_id' => Farm::factory(),
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    /**
     * Indicate that the request is rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'approved_by' => User::factory(),
            'approved_at' => now(),
            'rejected_reason' => $this->faker->sentence,
        ]);
    }
}

