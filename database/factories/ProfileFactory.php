<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'province' => $this->faker->state,
            'city' => $this->faker->city,
            'company' => $this->faker->company,
            'personnel_number' => $this->faker->unique()->randomNumber(8),
            'user_id' => User::factory(),
        ];
    }
}
