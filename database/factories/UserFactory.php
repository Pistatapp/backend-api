<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => $this->faker->unique()->userName,
            'mobile' => $this->faker->unique()->phoneNumber,
            'mobile_verified_at' => now(),
            'last_activity_at' => now(),
            'is_admin' => false,
        ];
    }
}
