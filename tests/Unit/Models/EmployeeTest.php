<?php

namespace Tests\Unit\Models;

use App\Models\Employee;
use App\Models\Farm;
use App\Models\User;
use App\Models\WorkerGpsData;
use App\Models\WorkerAttendanceSession;
use App\Models\WorkerDailyReport;
use App\Models\WorkerMonthlyPayroll;
use App\Models\WorkerShiftSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that employee belongs to farm.
     */
    public function test_employee_belongs_to_farm(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create(['farm_id' => $farm->id]);

        $this->assertInstanceOf(Farm::class, $employee->farm);
        $this->assertEquals($farm->id, $employee->farm->id);
    }

    /**
     * Test that employee can belong to user.
     */
    public function test_employee_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $employee->user);
        $this->assertEquals($user->id, $employee->user->id);
    }

    /**
     * Test that employee has many GPS data records.
     */
    public function test_employee_has_many_gps_data(): void
    {
        $employee = Employee::factory()->create();
        WorkerGpsData::factory()->count(3)->create(['employee_id' => $employee->id]);

        $this->assertCount(3, $employee->gpsData);
        $this->assertInstanceOf(WorkerGpsData::class, $employee->gpsData->first());
    }

    /**
     * Test that employee has many attendance sessions.
     */
    public function test_employee_has_many_attendance_sessions(): void
    {
        $employee = Employee::factory()->create();
        WorkerAttendanceSession::factory()->count(5)->create(['employee_id' => $employee->id]);

        $this->assertCount(5, $employee->attendanceSessions);
        $this->assertInstanceOf(WorkerAttendanceSession::class, $employee->attendanceSessions->first());
    }

    /**
     * Test that employee has many daily reports.
     */
    public function test_employee_has_many_daily_reports(): void
    {
        $employee = Employee::factory()->create();
        WorkerDailyReport::factory()->count(10)->create(['employee_id' => $employee->id]);

        $this->assertCount(10, $employee->dailyReports);
        $this->assertInstanceOf(WorkerDailyReport::class, $employee->dailyReports->first());
    }

    /**
     * Test that employee has many monthly payrolls.
     */
    public function test_employee_has_many_monthly_payrolls(): void
    {
        $employee = Employee::factory()->create();
        // Create monthly payrolls with unique month/year combinations
        $dates = [];
        for ($i = 0; $i < 6; $i++) {
            $month = ($i % 12) + 1;
            $year = 2024 + intval($i / 12);
            $dates[] = ['month' => $month, 'year' => $year];
        }
        foreach ($dates as $date) {
            WorkerMonthlyPayroll::factory()->create([
                'employee_id' => $employee->id,
                'month' => $date['month'],
                'year' => $date['year'],
            ]);
        }

        $this->assertCount(6, $employee->monthlyPayrolls);
        $this->assertInstanceOf(WorkerMonthlyPayroll::class, $employee->monthlyPayrolls->first());
    }

    /**
     * Test that employee has many shift schedules.
     */
    public function test_employee_has_many_shift_schedules(): void
    {
        $employee = Employee::factory()->create();
        WorkerShiftSchedule::factory()->count(7)->create(['employee_id' => $employee->id]);

        $this->assertCount(7, $employee->shiftSchedules);
        $this->assertInstanceOf(WorkerShiftSchedule::class, $employee->shiftSchedules->first());
    }

    /**
     * Test full name accessor.
     */
    public function test_employee_has_full_name_accessor(): void
    {
        $employee = Employee::factory()->create([
            'fname' => 'John',
            'lname' => 'Doe',
        ]);

        $this->assertEquals('John Doe', $employee->full_name);
    }

    /**
     * Test working scope filters only working employees.
     */
    public function test_working_scope_filters_working_employees(): void
    {
        Employee::factory()->count(3)->create(['is_working' => false]);
        Employee::factory()->count(2)->create(['is_working' => true]);

        $workingEmployees = Employee::working()->get();

        $this->assertCount(2, $workingEmployees);
        $workingEmployees->each(function ($employee) {
            $this->assertTrue($employee->is_working);
        });
    }

    /**
     * Test that work_days is cast to array.
     */
    public function test_work_days_is_cast_to_array(): void
    {
        $workDays = [0, 1, 2, 3, 4]; // Sunday to Thursday
        $employee = Employee::factory()->create(['work_days' => $workDays]);

        $this->assertIsArray($employee->work_days);
        $this->assertEquals($workDays, $employee->work_days);
    }

    /**
     * Test that is_working defaults to false.
     */
    public function test_is_working_defaults_to_false(): void
    {
        $employee = new Employee();
        $this->assertFalse($employee->is_working);
    }

    /**
     * Test that is_working is cast to boolean.
     */
    public function test_is_working_is_cast_to_boolean(): void
    {
        $employee = Employee::factory()->create(['is_working' => 1]);
        $this->assertIsBool($employee->is_working);
        $this->assertTrue($employee->is_working);
    }
}

