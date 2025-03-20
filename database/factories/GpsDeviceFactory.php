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
        static $imeiCounter = 0;

        return [
            'user_id' => \App\Models\User::factory(),
            'tractor_id' => \App\Models\Tractor::factory(),
            'name' => $this->faker->word,
            'imei' => '86307004338' . str_pad(++$imeiCounter, 4, '0', STR_PAD_LEFT),
            'sim_number' => $this->faker->numerify('##########'),
        ];
    }
}
