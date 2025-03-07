<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GpsDevice>
 */
class GpsDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'tractor_id' => \App\Models\Tractor::factory(),
            'name' => $this->faker->word,
            'imei' => $this->faker->randomNumber(15, true),
            'sim_number' => $this->faker->randomNumber(10, true),
        ];
    }
}
