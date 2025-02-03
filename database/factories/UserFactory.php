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
            'last_activity_at' => now(),
            'is_admin' => false,
        ];
    }

    /**
     * Indicate that the user's mobile number is unverified.
     *
     * @return \Database\Factories\UserFactory
     */
    public function unverified(): self
    {
        return $this->state(fn (array $attributes) => [
            'mobile_verified_at' => null,
        ]);
    }
}
