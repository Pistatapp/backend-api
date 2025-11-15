<?php

namespace Tests\Feature\Worker;

use App\Events\WorkerStatusChanged;
use App\Jobs\CloseAttendanceSessionsJob;
use App\Jobs\GenerateDailyAttendanceSummaryJob;
use App\Jobs\GenerateMonthlyPayrollJob;
use App\Jobs\ValidateWorkerShiftAttendanceJob;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\User;
use App\Models\WorkShift;
use App\Models\WorkerAttendanceSession;
use App\Models\WorkerDailyReport;
use App\Models\WorkerGpsData;
use App\Models\WorkerMonthlyPayroll;
use App\Models\WorkerShiftSchedule;
use App\Services\WorkerProductivityCalculator;
use App\Services\WorkerWageCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkerAttendanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private Employee $employee;
    private WorkShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::factory()->create();
        $this->farm->coordinates = [
            [51.3890, 35.6892], // SW corner
            [51.3890, 35.6900], // NW corner
            [51.3900, 35.6900], // NE corner
            [51.3900, 35.6892], // SE corner
            [51.3890, 35.6892], // Close polygon
        ];
        $this->farm->save();

        $this->employee = Employee::factory()->create([
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
        ]);

        $this->shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'work_hours' => 8.0,
        ]);
    }

    /**
     * Test complete workflow: GPS tracking -> Attendance session -> Daily report -> Payroll
     */
    public function test_complete_workflow_from_gps_to_payroll(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $this->employee->update(['user_id' => $user->id]);

        $date = Carbon::parse('2024-11-15');
        
        // 1. Schedule worker for shift
        $schedule = WorkerShiftSchedule::factory()->create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        // 2. Worker sends GPS reports (simulating mobile app)
        $entryTime = $date->copy()->setTime(8, 0, 0);
        $exitTime = $date->copy()->setTime(16, 30, 0); // 30 minutes overtime

        // Entry GPS points (in boundary)
        for ($hour = 8; $hour <= 16; $hour++) {
            WorkerGpsData::factory()->create([
                'employee_id' => $this->employee->id,
                'date_time' => $date->copy()->setTime($hour, 0, 0),
                'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
                'accuracy' => 10.0,
            ]);
        }

        // Exit GPS point (still in boundary, just overtime)
        WorkerGpsData::factory()->create([
            'employee_id' => $this->employee->id,
            'date_time' => $exitTime,
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
            'accuracy' => 10.0,
        ]);

        // 3. Validate shift attendance
        $validationJob = new ValidateWorkerShiftAttendanceJob($this->shift, $date);
        $validationJob->handle();

        $schedule->refresh();
        $this->assertEquals('completed', $schedule->status);

        // 4. Create attendance session manually (normally done by boundary detection service)
        $session = WorkerAttendanceSession::factory()->create([
            'employee_id' => $this->employee->id,
            'date' => $date,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'total_in_zone_duration' => 480, // 8 hours
            'total_out_zone_duration' => 30, // 30 minutes
            'status' => 'completed',
        ]);

        // 5. Generate daily attendance summary
        $dailySummaryJob = new GenerateDailyAttendanceSummaryJob($date);
        $productivityCalculator = app(WorkerProductivityCalculator::class);
        $wageCalculationService = app(WorkerWageCalculationService::class);
        $dailySummaryJob->handle($productivityCalculator, $wageCalculationService);

        $dailyReport = WorkerDailyReport::where('employee_id', $this->employee->id)
            ->whereDate('date', $date)
            ->first();

        $this->assertNotNull($dailyReport);
        $this->assertEquals(8.0, $dailyReport->scheduled_hours);
        $this->assertEquals(8.5, $dailyReport->actual_work_hours);
        $this->assertEquals(0.5, $dailyReport->overtime_hours);
        $this->assertEquals('pending', $dailyReport->status);

        // 6. Approve daily report
        $dailyReport->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => Carbon::now(),
        ]);

        // 7. Generate monthly payroll
        $fromDate = Carbon::parse('2024-11-01');
        $toDate = Carbon::parse('2024-11-30');
        $payrollJob = new GenerateMonthlyPayrollJob($fromDate, $toDate);
        $wageCalculationService = app(WorkerWageCalculationService::class);
        $payrollJob->handle($wageCalculationService);

        $payroll = WorkerMonthlyPayroll::where('employee_id', $this->employee->id)
            ->where('month', 11)
            ->where('year', 2024)
            ->first();

        $this->assertNotNull($payroll);
        $this->assertEquals(8.5, $payroll->total_work_hours);
        $this->assertEquals(8.0, $payroll->total_required_hours);
        $this->assertEquals(0.5, $payroll->total_overtime_hours);
        $this->assertEquals(800000, $payroll->base_wage_total); // 8.0 * 100000
        $this->assertEquals(75000, $payroll->overtime_wage_total); // 0.5 * 150000
        $this->assertEquals(875000, $payroll->final_total);
    }

    /**
     * Test workflow handles worker absence.
     */
    public function test_workflow_handles_worker_absence(): void
    {
        $date = Carbon::parse('2024-11-15');
        
        // Schedule worker
        $schedule = WorkerShiftSchedule::factory()->create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        // No GPS data sent

        // Validate shift attendance
        $validationJob = new ValidateWorkerShiftAttendanceJob($this->shift, $date);
        $validationJob->handle();

        $schedule->refresh();
        $this->assertEquals('missed', $schedule->status);

        // Generate daily summary (should create absent report)
        $dailySummaryJob = new GenerateDailyAttendanceSummaryJob($date);
        $productivityCalculator = app(WorkerProductivityCalculator::class);
        $wageCalculationService = app(WorkerWageCalculationService::class);
        $dailySummaryJob->handle($productivityCalculator, $wageCalculationService);

        $dailyReport = WorkerDailyReport::where('employee_id', $this->employee->id)
            ->whereDate('date', $date)
            ->first();

        $this->assertNotNull($dailyReport);
        $this->assertEquals(0, $dailyReport->actual_work_hours);
        $this->assertEquals(0, $dailyReport->overtime_hours);
    }

    /**
     * Test workflow with session closure.
     */
    public function test_workflow_with_session_closure(): void
    {
        $date = Carbon::yesterday();

        // Create stale session from yesterday
        $session = WorkerAttendanceSession::factory()->create([
            'employee_id' => $this->employee->id,
            'date' => $date,
            'status' => 'in_progress',
            'updated_at' => Carbon::now()->subHours(2),
        ]);

        // Close stale sessions
        $closeJob = new CloseAttendanceSessionsJob();
        $closeJob->handle();

        $session->refresh();
        $this->assertEquals('completed', $session->status);
        $this->assertNotNull($session->exit_time);
    }

    /**
     * Test workflow with multiple shifts per day.
     */
    public function test_workflow_with_multiple_shifts_per_day(): void
    {
        $date = Carbon::parse('2024-11-15');

        $morningShift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'work_hours' => 4.0,
        ]);

        $eveningShift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '13:00:00',
            'end_time' => '17:00:00',
            'work_hours' => 4.0,
        ]);

        // Schedule both shifts
        WorkerShiftSchedule::factory()->create([
            'employee_id' => $this->employee->id,
            'shift_id' => $morningShift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        WorkerShiftSchedule::factory()->create([
            'employee_id' => $this->employee->id,
            'shift_id' => $eveningShift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        // Create attendance session covering both shifts
        $session = WorkerAttendanceSession::factory()->create([
            'employee_id' => $this->employee->id,
            'date' => $date,
            'entry_time' => $date->copy()->setTime(8, 0, 0),
            'exit_time' => $date->copy()->setTime(17, 0, 0),
            'total_in_zone_duration' => 480, // 8 hours
            'total_out_zone_duration' => 60,  // 1 hour break
            'status' => 'completed',
        ]);

        // Generate daily summary
        $dailySummaryJob = new GenerateDailyAttendanceSummaryJob($date);
        $productivityCalculator = app(WorkerProductivityCalculator::class);
        $wageCalculationService = app(WorkerWageCalculationService::class);
        $dailySummaryJob->handle($productivityCalculator, $wageCalculationService);

        $dailyReport = WorkerDailyReport::where('employee_id', $this->employee->id)
            ->whereDate('date', $date)
            ->first();

        $this->assertNotNull($dailyReport);
        $this->assertEquals(8.0, $dailyReport->scheduled_hours); // 4 + 4
        $this->assertEquals(9.0, $dailyReport->actual_work_hours); // (480 + 60) / 60
        $this->assertEquals(1.0, $dailyReport->overtime_hours); // 9.0 - 8.0
    }
}

