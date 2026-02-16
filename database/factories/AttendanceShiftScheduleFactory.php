<?php

namespace Database\Factories;

use App\Models\AttendanceShiftSchedule;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceShiftScheduleFactory extends Factory
{
    protected $model = AttendanceShiftSchedule::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'shift_id' => WorkShift::factory(),
            'scheduled_date' => $this->faker->dateTimeBetween('now', '+30 days'),
            'status' => $this->faker->randomElement(['scheduled', 'completed', 'missed', 'cancelled']),
        ];
    }
}
