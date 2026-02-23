<?php

namespace Database\Factories;

use App\Models\AttendanceGpsData;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceGpsDataFactory extends Factory
{
    protected $model = AttendanceGpsData::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'coordinate' => [
                $this->faker->latitude(35.6, 35.7),
                $this->faker->longitude(51.3, 51.4),
            ],
            'speed' => $this->faker->randomFloat(2, 0, 10),
            'date_time' => Carbon::now()->subMinutes($this->faker->numberBetween(0, 60)),
        ];
    }
}
