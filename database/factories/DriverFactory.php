<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Driver>
 */
class DriverFactory extends Factory
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
            'mobile' => $this->faker->phoneNumber,
            'employee_code' => $this->faker->randomNumber(7, true),
            'tractor_id' => \App\Models\Tractor::factory(),
        ];
    }
}
