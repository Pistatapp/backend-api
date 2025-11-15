<?php

namespace App\Jobs;

use App\Models\WorkShift;
use App\Models\WorkerShiftSchedule;
use App\Models\WorkerGpsData;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ValidateWorkerShiftAttendanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public WorkShift $shift,
        public Carbon $date
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        // Get all workers scheduled for this shift on this date
        $schedules = WorkerShiftSchedule::where('shift_id', $this->shift->id)
            ->whereDate('scheduled_date', $this->date)
            ->where('status', 'scheduled')
            ->with('employee')
            ->get();

        foreach ($schedules as $schedule) {
            $employee = $schedule->employee;
            
            if (!$employee) {
                continue;
            }

            // Calculate shift time window
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
            
            // Handle shifts that span midnight
            if ($shiftEnd->lt($shiftStart)) {
                $shiftEnd->addDay();
            }

            // Check GPS data during shift time
            $gpsData = WorkerGpsData::where('employee_id', $employee->id)
                ->whereBetween('date_time', [$shiftStart, $shiftEnd])
                ->get();

            // Check for issues
            if ($gpsData->isEmpty()) {
                // Worker absent - no GPS data
                $this->sendAbsentNotification($employee, $this->shift, $this->date);
                $schedule->update(['status' => 'missed']);
            } else {
                // Check if worker was present for at least 50% of shift duration
                $shiftDurationMinutes = $shiftStart->diffInMinutes($shiftEnd);
                $requiredPresenceMinutes = $shiftDurationMinutes * 0.5;
                
                // Count GPS points with good accuracy (< 20m)
                $validGpsPoints = $gpsData->filter(function ($point) {
                    return $point->accuracy && $point->accuracy < 20;
                });

                if ($validGpsPoints->isEmpty()) {
                    // GPS accuracy unreliable
                    $this->sendUnreliableGpsNotification($employee, $this->shift, $this->date);
                } else {
                    // Calculate time present (simplified: count of valid points * average interval)
                    // For more accuracy, we'd need to calculate actual time spans
                    $timePresent = $this->calculateTimePresent($validGpsPoints, $shiftStart, $shiftEnd);
                    
                    if ($timePresent < $requiredPresenceMinutes) {
                        // Worker present but less than 50% of shift duration
                        $this->sendInsufficientPresenceNotification($employee, $this->shift, $this->date, $timePresent, $requiredPresenceMinutes);
                    } else {
                        // Worker present and meets requirements
                        $schedule->update(['status' => 'completed']);
                    }
                }
            }
        }
    }

    /**
     * Calculate time present from GPS points
     *
     * @param \Illuminate\Support\Collection $gpsPoints
     * @param Carbon $shiftStart
     * @param Carbon $shiftEnd
     * @return float Minutes present
     */
    private function calculateTimePresent($gpsPoints, Carbon $shiftStart, Carbon $shiftEnd): float
    {
        if ($gpsPoints->isEmpty()) {
            return 0;
        }

        // Simple calculation: assume each GPS point represents presence
        // In a real implementation, we'd calculate actual time spans between points
        $firstPoint = $gpsPoints->first();
        $lastPoint = $gpsPoints->last();
        
        return $firstPoint->date_time->diffInMinutes($lastPoint->date_time);
    }

    /**
     * Send absent notification
     *
     * @param Employee $employee
     * @param WorkShift $shift
     * @param Carbon $date
     * @return void
     */
    private function sendAbsentNotification(Employee $employee, WorkShift $shift, Carbon $date): void
    {
        // TODO: Implement notification system
        Log::warning('Worker absent', [
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'date' => $date->toDateString(),
        ]);
    }

    /**
     * Send unreliable GPS notification
     *
     * @param Employee $employee
     * @param WorkShift $shift
     * @param Carbon $date
     * @return void
     */
    private function sendUnreliableGpsNotification(Employee $employee, WorkShift $shift, Carbon $date): void
    {
        // TODO: Implement notification system
        Log::warning('GPS accuracy unreliable', [
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'date' => $date->toDateString(),
        ]);
    }

    /**
     * Send insufficient presence notification
     *
     * @param Employee $employee
     * @param WorkShift $shift
     * @param Carbon $date
     * @param float $timePresent
     * @param float $requiredTime
     * @return void
     */
    private function sendInsufficientPresenceNotification(Employee $employee, WorkShift $shift, Carbon $date, float $timePresent, float $requiredTime): void
    {
        // TODO: Implement notification system
        Log::warning('Worker present but less than 50% of shift duration', [
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'date' => $date->toDateString(),
            'time_present' => $timePresent,
            'required_time' => $requiredTime,
        ]);
    }
}
