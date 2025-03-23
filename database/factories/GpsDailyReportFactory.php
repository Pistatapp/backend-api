<?php

namespace Database\Factories;

use App\Models\Tractor;
use App\Models\TractorTask;
use Illuminate\Database\Eloquent\Factories\Factory;

class GpsDailyReportFactory extends Factory
{
    public function definition()
    {
        return [
            'tractor_id' => Tractor::factory(),
            'traveled_distance' => $this->faker->randomFloat(2, 0, 100),
            'work_duration' => $this->faker->numberBetween(0, 3600),
            'stoppage_count' => $this->faker->numberBetween(0, 10),
            'stoppage_duration' => $this->faker->numberBetween(0, 3600),
            'average_speed' => $this->faker->randomFloat(2, 0, 50),
            'max_speed' => $this->faker->randomFloat(2, 0, 100),
            'efficiency' => $this->faker->randomFloat(2, 0, 100),
            'date' => $this->faker->date(),
            'tractor_task_id' => TractorTask::factory(),
        ];
    }
}
