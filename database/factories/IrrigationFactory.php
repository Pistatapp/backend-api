<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\Labour;
use App\Models\Pump;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Irrigation>
 */
class IrrigationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = $this->faker->date();
        return [
            'farm_id' => Farm::factory(),
            'labour_id' => Labour::factory(),
            'pump_id' => Pump::factory(),
            'start_date' => $date,
            'start_time' => $startTime = $this->faker->time(),
            'end_time' => $this->faker->time($format = 'H:i:s', $max = '23:59:59', $min = $startTime),
            'note' => $this->faker->text,
            'status' => 'pending',
            'created_by' => User::factory(),
        ];
    }
}
