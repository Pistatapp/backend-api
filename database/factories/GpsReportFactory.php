<?php

namespace Database\Factories;

use App\Models\GpsDevice;
use App\Models\GpsReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GpsReport>
 */
class GpsReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gps_device_id' => GpsDevice::factory(),
            'imei' => fn (array $attributes) => GpsDevice::find($attributes['gps_device_id'])->imei,
            'coordinate' => [
                fake()->latitude(34.0, 35.0),
                fake()->longitude(50.0, 51.0),
            ],
            'speed' => fake()->numberBetween(0, 40),
            'status' => fake()->boolean(),
            'is_stopped' => false,
            'stoppage_time' => 0,
            'is_starting_point' => false,
            'is_ending_point' => false,
            'date_time' => fake()->dateTimeBetween('-1 day', 'now'),
        ];
    }
}
