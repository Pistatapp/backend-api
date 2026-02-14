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
            'tractor_id' => null,
            'name' => $this->faker->word,
            'imei' => '86307004338' . str_pad(++$imeiCounter, 4, '0', STR_PAD_LEFT),
            'sim_number' => $this->faker->numerify('##########'),
            'device_type' => 'tractor_gps',
            'device_fingerprint' => null,
        ];
    }

    /**
     * Indicate that the device is a mobile phone.
     */
    public function mobilePhone(): static
    {
        return $this->state(fn (array $attributes) => [
            'device_type' => 'mobile_phone',
            'device_fingerprint' => $this->faker->unique()->sha256,
            'tractor_id' => null,
            'imei' => null,
            'sim_number' => null, // Mobile phones may not have SIM number
        ]);
    }

    /**
     * Indicate that the device is a personal GPS.
     */
    public function personalGps(): static
    {
        return $this->state(fn (array $attributes) => [
            'device_type' => 'personal_gps',
            'device_fingerprint' => null,
            'tractor_id' => null,
        ]);
    }
}
