<?php

namespace Database\Factories;

use App\Models\Labour;
use App\Models\WorkShift;
use App\Models\LabourShiftSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LabourShiftSchedule>
 */
class LabourShiftScheduleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = LabourShiftSchedule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'labour_id' => Labour::factory(),
            'shift_id' => WorkShift::factory(),
            'scheduled_date' => $this->faker->dateTimeBetween('now', '+30 days'),
            'status' => $this->faker->randomElement(['scheduled', 'completed', 'missed', 'cancelled']),
        ];
    }
}

