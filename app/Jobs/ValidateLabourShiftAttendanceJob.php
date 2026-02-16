<?php

namespace App\Jobs;

use App\Models\WorkShift;
use App\Models\AttendanceShiftSchedule;
use App\Models\AttendanceGpsData;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ValidateLabourShiftAttendanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(
        public WorkShift $shift,
        public Carbon $date
    ) {}

    public function handle(): void
    {
        $schedules = AttendanceShiftSchedule::where('shift_id', $this->shift->id)
            ->whereDate('scheduled_date', $this->date)
            ->where('status', 'scheduled')
            ->with('user')
            ->get();

        foreach ($schedules as $schedule) {
            $user = $schedule->user;

            if (! $user) {
                continue;
            }

            $shiftStart = $this->date->copy()->setTime(
                $this->shift->start_time->hour,
                $this->shift->start_time->minute,
                $this->shift->start_time->second
            );
            $shiftEnd = $this->date->copy()->setTime(
                $this->shift->end_time->hour,
                $this->shift->end_time->minute,
                $this->shift->end_time->second
            );

            if ($shiftEnd->lt($shiftStart)) {
                $shiftEnd->addDay();
            }

            $gpsData = AttendanceGpsData::where('user_id', $user->id)
                ->whereBetween('date_time', [$shiftStart, $shiftEnd])
                ->get();

            if ($gpsData->isEmpty()) {
                $this->sendAbsentNotification($user, $this->shift, $this->date);
                $schedule->update(['status' => 'missed']);
            } else {
                $shiftDurationMinutes = $shiftStart->diffInMinutes($shiftEnd);
                $requiredPresenceMinutes = $shiftDurationMinutes * 0.5;

                $validGpsPoints = $gpsData->filter(function ($point) {
                    return $point->accuracy && $point->accuracy < 20;
                });

                if ($validGpsPoints->isEmpty()) {
                    $this->sendUnreliableGpsNotification($user, $this->shift, $this->date);
                } else {
                    $timePresent = $this->calculateTimePresent($validGpsPoints, $shiftStart, $shiftEnd);

                    if ($timePresent < $requiredPresenceMinutes) {
                        $this->sendInsufficientPresenceNotification($user, $this->shift, $this->date, $timePresent, $requiredPresenceMinutes);
                    } else {
                        $schedule->update(['status' => 'completed']);
                    }
                }
            }
        }
    }

    private function calculateTimePresent($gpsPoints, Carbon $shiftStart, Carbon $shiftEnd): float
    {
        if ($gpsPoints->isEmpty()) {
            return 0;
        }

        $firstPoint = $gpsPoints->first();
        $lastPoint = $gpsPoints->last();

        return $firstPoint->date_time->diffInMinutes($lastPoint->date_time);
    }

    private function sendAbsentNotification(User $user, WorkShift $shift, Carbon $date): void
    {
        Log::warning('User absent from shift', [
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'date' => $date->toDateString(),
        ]);
    }

    private function sendUnreliableGpsNotification(User $user, WorkShift $shift, Carbon $date): void
    {
        Log::warning('GPS accuracy unreliable', [
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'date' => $date->toDateString(),
        ]);
    }

    private function sendInsufficientPresenceNotification(User $user, WorkShift $shift, Carbon $date, float $timePresent, float $requiredTime): void
    {
        Log::warning('User present but less than 50% of shift duration', [
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'date' => $date->toDateString(),
            'time_present' => $timePresent,
            'required_time' => $requiredTime,
        ]);
    }
}
