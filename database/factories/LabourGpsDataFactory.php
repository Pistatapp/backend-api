<?php

namespace Database\Factories;

use App\Models\Labour;
use App\Models\LabourGpsData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LabourGpsData>
 */
class LabourGpsDataFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = LabourGpsData::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'labour_id' => Labour::factory(),
            'coordinate' => [
                'lat' => $this->faker->latitude(35.6, 35.7),
                'lng' => $this->faker->longitude(51.3, 51.4),
                'altitude' => $this->faker->randomFloat(2, 1000, 1500),
            ],
            'speed' => $this->faker->randomFloat(2, 0, 10),
            'bearing' => $this->faker->randomFloat(2, 0, 360),
            'accuracy' => $this->faker->randomFloat(2, 5, 50),
            'provider' => $this->faker->randomElement(['gps', 'network', 'passive']),
            'date_time' => Carbon::now()->subMinutes($this->faker->numberBetween(0, 60)),
        ];
    }
}

