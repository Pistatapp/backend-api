<?php

namespace Database\Factories;

use App\Models\AttendanceSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceSessionFactory extends Factory
{
    protected $model = AttendanceSession::class;

    public function definition(): array
    {
        $date = $this->faker->dateTimeBetween('-30 days', 'now');
        $entryTime = Carbon::parse($date)->setTime(8, 0, 0);
        $exitTime = $entryTime->copy()->addHours(8);

        return [
            'user_id' => User::factory(),
            'date' => $date,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'total_in_zone_duration' => $this->faker->numberBetween(400, 480),
            'total_out_zone_duration' => $this->faker->numberBetween(0, 60),
            'status' => $this->faker->randomElement(['in_progress', 'completed']),
        ];
    }
}
