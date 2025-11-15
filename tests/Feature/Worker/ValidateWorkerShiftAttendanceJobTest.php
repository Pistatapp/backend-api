<?php

namespace Tests\Feature\Worker;

use App\Jobs\ValidateWorkerShiftAttendanceJob;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\WorkShift;
use App\Models\WorkerGpsData;
use App\Models\WorkerShiftSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ValidateWorkerShiftAttendanceJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test job marks schedule as missed when no GPS data.
     */
    public function test_job_marks_schedule_as_missed_when_no_gps_data(): void
    {
        Log::spy();

        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create(['farm_id' => $farm->id]);
        $shift = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $date = Carbon::today();
        $schedule = WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $job = new ValidateWorkerShiftAttendanceJob($shift, $date);
        $job->handle();

        $schedule->refresh();
        $this->assertEquals('missed', $schedule->status);

        Log::assertLogged('warning', function ($message, $context) use ($employee, $shift, $date) {
            return str_contains($message, 'Worker absent') &&
                   $context['employee_id'] === $employee->id &&
                   $context['shift_id'] === $shift->id &&
                   $context['date'] === $date->toDateString();
        });
    }

    /**
     * Test job marks schedule as completed when GPS data present.
     */
    public function test_job_marks_schedule_as_completed_when_gps_data_present(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create(['farm_id' => $farm->id]);
        $shift = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $date = Carbon::today();
        $schedule = WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        // Create GPS data during shift time
        $shiftStart = $date->copy()->setTime(8, 0, 0);
        $shiftEnd = $date->copy()->setTime(16, 0, 0);

        WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $shiftStart->copy()->addHours(2),
            'accuracy' => 10.0, // Good accuracy
        ]);

        WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $shiftEnd->copy()->subHours(2),
            'accuracy' => 10.0, // Good accuracy
        ]);

        $job = new ValidateWorkerShiftAttendanceJob($shift, $date);
        $job->handle();

        $schedule->refresh();
        $this->assertEquals('completed', $schedule->status);
    }

    /**
     * Test job logs warning when GPS accuracy unreliable.
     */
    public function test_job_logs_warning_when_gps_accuracy_unreliable(): void
    {
        Log::spy();

        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create(['farm_id' => $farm->id]);
        $shift = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $date = Carbon::today();
        $schedule = WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        // Create GPS data with poor accuracy (> 20m)
        $shiftStart = $date->copy()->setTime(8, 0, 0);
        WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $shiftStart->copy()->addHours(1),
            'accuracy' => 25.0, // Poor accuracy
        ]);

        $job = new ValidateWorkerShiftAttendanceJob($shift, $date);
        $job->handle();

        Log::assertLogged('warning', function ($message, $context) use ($employee, $shift, $date) {
            return str_contains($message, 'GPS accuracy unreliable') &&
                   $context['employee_id'] === $employee->id;
        });
    }

    /**
     * Test job handles shifts that span midnight.
     */
    public function test_job_handles_shifts_that_span_midnight(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create(['farm_id' => $farm->id]);
        $shift = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'start_time' => '22:00:00', // 10 PM
            'end_time' => '06:00:00',   // 6 AM next day
        ]);

        $date = Carbon::today();
        $schedule = WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        // Create GPS data during night shift
        $shiftStart = $date->copy()->setTime(22, 0, 0);
        $shiftEnd = $date->copy()->addDay()->setTime(6, 0, 0);

        WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $shiftStart->copy()->addHours(2), // 12 AM
            'accuracy' => 10.0,
        ]);

        WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $shiftEnd->copy()->subHours(1), // 5 AM
            'accuracy' => 10.0,
        ]);

        $job = new ValidateWorkerShiftAttendanceJob($shift, $date);
        $job->handle();

        $schedule->refresh();
        $this->assertEquals('completed', $schedule->status);
    }

    /**
     * Test job handles multiple scheduled workers.
     */
    public function test_job_handles_multiple_scheduled_workers(): void
    {
        $farm = Farm::factory()->create();
        $shift = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $employee1 = Employee::factory()->create(['farm_id' => $farm->id]);
        $employee2 = Employee::factory()->create(['farm_id' => $farm->id]);

        $date = Carbon::today();
        
        $schedule1 = WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee1->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $schedule2 = WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee2->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        // Only employee1 has GPS data - create multiple points to meet 50% threshold
        $shiftStart = $date->copy()->setTime(8, 0, 0);
        $shiftEnd = $date->copy()->setTime(16, 0, 0);
        WorkerGpsData::factory()->create([
            'employee_id' => $employee1->id,
            'date_time' => $shiftStart->copy()->addHours(2),
            'accuracy' => 10.0,
        ]);
        WorkerGpsData::factory()->create([
            'employee_id' => $employee1->id,
            'date_time' => $shiftEnd->copy()->subHours(2),
            'accuracy' => 10.0,
        ]);

        $job = new ValidateWorkerShiftAttendanceJob($shift, $date);
        $job->handle();

        $schedule1->refresh();
        $schedule2->refresh();

        $this->assertEquals('completed', $schedule1->status);
        $this->assertEquals('missed', $schedule2->status);
    }

    /**
     * Test job only validates scheduled status.
     */
    public function test_job_only_validates_scheduled_status(): void
    {
        $farm = Farm::factory()->create();
        $employee1 = Employee::factory()->create(['farm_id' => $farm->id]);
        $employee2 = Employee::factory()->create(['farm_id' => $farm->id]);
        $shift = WorkShift::factory()->create(['farm_id' => $farm->id]);

        $date = Carbon::today();
        
        $scheduledSchedule = WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee1->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $completedSchedule = WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee2->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'completed',
        ]);

        $job = new ValidateWorkerShiftAttendanceJob($shift, $date);
        $job->handle();

        $completedSchedule->refresh();
        // Should remain completed, not re-validated
        $this->assertEquals('completed', $completedSchedule->status);
    }
}

