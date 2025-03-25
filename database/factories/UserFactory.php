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
            'fcm_token' => 'd9qXYvAPRPeAlAfGiDtw5R:APA91bGJBYyf_JzzC_FUdzbv4ZBHZ3ZYNZiVZTegDsy7I8xWKiuu4VDxxlYhlo_x3C8gQe-xkjuCYte_O8y8-T-tbbNrXmxLElcgeLZhRO9wB7TX2pmfiCI'
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
