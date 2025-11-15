<?php

namespace Tests\Feature\Worker;

use App\Jobs\GenerateDailyAttendanceSummaryJob;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\WorkerAttendanceSession;
use App\Models\WorkerDailyReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GenerateDailyAttendanceSummaryJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test job creates daily report from attendance session.
     */
    public function test_job_creates_daily_report_from_attendance_session(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'work_days' => [1], // Monday
            'work_hours' => 8.0,
        ]);

        $date = Carbon::parse('2024-11-11'); // Monday
        $session = WorkerAttendanceSession::factory()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'total_in_zone_duration' => 450, // 7.5 hours in minutes
            'total_out_zone_duration' => 60,  // 1 hour in minutes
            'status' => 'completed',
        ]);

        $job = new GenerateDailyAttendanceSummaryJob($date);
        app()->call([$job, 'handle']);

        $report = WorkerDailyReport::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();

        $this->assertNotNull($report);
        $this->assertEquals(8.0, $report->scheduled_hours); // Required hours
        $this->assertEquals(8.5, $report->actual_work_hours); // (450 + 60) / 60
        $this->assertEquals(0.5, $report->overtime_hours); // 8.5 - 8.0
        $this->assertEquals(60, $report->time_outside_zone);
        $this->assertNotNull($report->productivity_score);
        $this->assertEquals('pending', $report->status);
    }

    /**
     * Test job creates absent report when no attendance session.
     */
    public function test_job_creates_absent_report_when_no_attendance_session(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'work_days' => [1], // Monday
            'work_hours' => 8.0,
        ]);

        $date = Carbon::parse('2024-11-11'); // Monday
        // No attendance session created

        $job = new GenerateDailyAttendanceSummaryJob($date);
        app()->call([$job, 'handle']);

        $report = WorkerDailyReport::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();

        $this->assertNotNull($report);
        $this->assertEquals(8.0, $report->scheduled_hours);
        $this->assertEquals(0, $report->actual_work_hours);
        $this->assertEquals(0, $report->overtime_hours);
        $this->assertEquals(0, $report->time_outside_zone);
        $this->assertNull($report->productivity_score);
        $this->assertEquals('pending', $report->status);
    }

    /**
     * Test job updates existing report if it exists.
     */
    public function test_job_updates_existing_report_if_it_exists(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'work_days' => [1],
            'work_hours' => 8.0,
        ]);

        $date = Carbon::parse('2024-11-11');
        
        // Create existing report
        $existingReport = WorkerDailyReport::factory()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => 'pending',
        ]);

        // Create attendance session
        $session = WorkerAttendanceSession::factory()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'total_in_zone_duration' => 480, // 8 hours
            'total_out_zone_duration' => 0,
        ]);

        $job = new GenerateDailyAttendanceSummaryJob($date);
        app()->call([$job, 'handle']);

        $report = WorkerDailyReport::find($existingReport->id);
        $this->assertEquals(8.0, $report->actual_work_hours);
    }

    /**
     * Test job handles multiple employees.
     */
    public function test_job_handles_multiple_employees(): void
    {
        $farm = Farm::factory()->create();
        
        $employee1 = Employee::factory()->create([
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'work_days' => [1],
            'work_hours' => 8.0,
        ]);

        $employee2 = Employee::factory()->create([
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'work_days' => [1],
            'work_hours' => 8.0,
        ]);

        $date = Carbon::parse('2024-11-11');

        WorkerAttendanceSession::factory()->create([
            'employee_id' => $employee1->id,
            'date' => $date,
            'total_in_zone_duration' => 480,
            'total_out_zone_duration' => 0,
        ]);

        WorkerAttendanceSession::factory()->create([
            'employee_id' => $employee2->id,
            'date' => $date,
            'total_in_zone_duration' => 360,
            'total_out_zone_duration' => 120,
        ]);

        $job = new GenerateDailyAttendanceSummaryJob($date);
        app()->call([$job, 'handle']);

        $reports = WorkerDailyReport::whereDate('date', $date)->get();
        $this->assertCount(2, $reports);
    }

    /**
     * Test job handles errors gracefully.
     */
    public function test_job_handles_errors_gracefully(): void
    {
        Log::spy();

        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'work_days' => null, // Invalid work_days
            'work_hours' => 8.0,
        ]);

        $date = Carbon::parse('2024-11-11');

        WorkerAttendanceSession::factory()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'total_in_zone_duration' => 480,
            'total_out_zone_duration' => 0,
        ]);

        $job = new GenerateDailyAttendanceSummaryJob($date);
        
        // Should not throw exception
        app()->call([$job, 'handle']);

        Log::assertLogged('error', function ($message, $context) use ($employee, $date) {
            return str_contains($message, 'Error generating daily report') &&
                   $context['employee_id'] === $employee->id &&
                   $context['date'] === $date->toDateString();
        });
    }

    /**
     * Test job calculates productivity score correctly.
     */
    public function test_job_calculates_productivity_score_correctly(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'work_days' => [1],
            'work_hours' => 8.0,
        ]);

        $date = Carbon::parse('2024-11-11');
        
        // 400 minutes in zone, 100 minutes out of zone = 80% productivity
        $session = WorkerAttendanceSession::factory()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'total_in_zone_duration' => 400,
            'total_out_zone_duration' => 100,
        ]);

        $job = new GenerateDailyAttendanceSummaryJob($date);
        app()->call([$job, 'handle']);

        $report = WorkerDailyReport::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();

        $this->assertNotNull($report);
        $this->assertEquals(80.0, $report->productivity_score);
    }
}

