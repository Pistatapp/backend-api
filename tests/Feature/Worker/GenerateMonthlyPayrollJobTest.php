<?php

namespace Tests\Feature\Worker;

use App\Jobs\GenerateMonthlyPayrollJob;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\User;
use App\Models\WorkerDailyReport;
use App\Models\WorkerMonthlyPayroll;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GenerateMonthlyPayrollJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test job generates monthly payroll from approved reports.
     */
    public function test_job_generates_monthly_payroll_from_approved_reports(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'hourly_wage' => 100000, // 100,000 per hour
            'overtime_hourly_wage' => 150000, // 150,000 per hour
        ]);

        $fromDate = Carbon::parse('2024-11-01');
        $toDate = Carbon::parse('2024-11-30');

        // Create approved daily reports
        WorkerDailyReport::factory()->create([
            'employee_id' => $employee->id,
            'date' => Carbon::parse('2024-11-15'),
            'scheduled_hours' => 8.0,
            'actual_work_hours' => 8.5,
            'overtime_hours' => 0.5,
            'status' => 'approved',
        ]);

        WorkerDailyReport::factory()->create([
            'employee_id' => $employee->id,
            'date' => Carbon::parse('2024-11-16'),
            'scheduled_hours' => 8.0,
            'actual_work_hours' => 9.0,
            'overtime_hours' => 1.0,
            'status' => 'approved',
        ]);

        // Pending report (should not be included)
        WorkerDailyReport::factory()->create([
            'employee_id' => $employee->id,
            'date' => Carbon::parse('2024-11-17'),
            'scheduled_hours' => 8.0,
            'actual_work_hours' => 8.0,
            'overtime_hours' => 0.0,
            'status' => 'pending',
        ]);

        $job = new GenerateMonthlyPayrollJob($fromDate, $toDate);
        app()->call([$job, 'handle']);

        $payroll = WorkerMonthlyPayroll::where('employee_id', $employee->id)
            ->where('month', 11)
            ->where('year', 2024)
            ->first();

        $this->assertNotNull($payroll);
        $this->assertEquals(17.5, $payroll->total_work_hours); // 8.5 + 9.0
        $this->assertEquals(16.0, $payroll->total_required_hours); // 8.0 + 8.0
        $this->assertEquals(1.5, $payroll->total_overtime_hours); // 0.5 + 1.0
        $this->assertEquals(1600000, $payroll->base_wage_total); // 16.0 * 100000
        $this->assertEquals(225000, $payroll->overtime_wage_total); // 1.5 * 150000
        $this->assertEquals(1825000, $payroll->final_total); // 1600000 + 225000
    }

    /**
     * Test job only includes approved reports.
     */
    public function test_job_only_includes_approved_reports(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'hourly_wage' => 100000,
        ]);

        $fromDate = Carbon::parse('2024-11-01');
        $toDate = Carbon::parse('2024-11-30');

        // Approved report
        WorkerDailyReport::factory()->create([
            'employee_id' => $employee->id,
            'date' => Carbon::parse('2024-11-15'),
            'scheduled_hours' => 8.0,
            'actual_work_hours' => 8.0,
            'overtime_hours' => 0.0,
            'status' => 'approved',
        ]);

        // Pending report (should not be included)
        WorkerDailyReport::factory()->create([
            'employee_id' => $employee->id,
            'date' => Carbon::parse('2024-11-16'),
            'scheduled_hours' => 8.0,
            'actual_work_hours' => 8.0,
            'overtime_hours' => 0.0,
            'status' => 'pending',
        ]);

        $job = new GenerateMonthlyPayrollJob($fromDate, $toDate);
        app()->call([$job, 'handle']);

        $payroll = WorkerMonthlyPayroll::where('employee_id', $employee->id)->first();

        $this->assertNotNull($payroll);
        $this->assertEquals(8.0, $payroll->total_work_hours); // Only approved report
        $this->assertEquals(8.0, $payroll->total_required_hours);
    }

    /**
     * Test job generates payroll for specific employee when employeeId provided.
     */
    public function test_job_generates_payroll_for_specific_employee_when_employee_id_provided(): void
    {
        $farm = Farm::factory()->create();
        
        $employee1 = Employee::factory()->create([
            'farm_id' => $farm->id,
            'hourly_wage' => 100000,
        ]);

        $employee2 = Employee::factory()->create([
            'farm_id' => $farm->id,
            'hourly_wage' => 100000,
        ]);

        $fromDate = Carbon::parse('2024-11-01');
        $toDate = Carbon::parse('2024-11-30');

        WorkerDailyReport::factory()->create([
            'employee_id' => $employee1->id,
            'date' => Carbon::parse('2024-11-15'),
            'scheduled_hours' => 8.0,
            'actual_work_hours' => 8.0,
            'overtime_hours' => 0.0,
            'status' => 'approved',
        ]);

        WorkerDailyReport::factory()->create([
            'employee_id' => $employee2->id,
            'date' => Carbon::parse('2024-11-15'),
            'scheduled_hours' => 8.0,
            'actual_work_hours' => 8.0,
            'overtime_hours' => 0.0,
            'status' => 'approved',
        ]);

        $job = new GenerateMonthlyPayrollJob($fromDate, $toDate, $employee1->id);
        app()->call([$job, 'handle']);

        $payrolls = WorkerMonthlyPayroll::where('month', 11)
            ->where('year', 2024)
            ->get();

        $this->assertCount(1, $payrolls);
        $this->assertEquals($employee1->id, $payrolls->first()->employee_id);
    }

    /**
     * Test job handles employees with no approved reports.
     */
    public function test_job_handles_employees_with_no_approved_reports(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'hourly_wage' => 100000,
        ]);

        $fromDate = Carbon::parse('2024-11-01');
        $toDate = Carbon::parse('2024-11-30');

        // Only pending reports
        WorkerDailyReport::factory()->create([
            'employee_id' => $employee->id,
            'date' => Carbon::parse('2024-11-15'),
            'status' => 'pending',
        ]);

        $job = new GenerateMonthlyPayrollJob($fromDate, $toDate);
        app()->call([$job, 'handle']);

        $payroll = WorkerMonthlyPayroll::where('employee_id', $employee->id)->first();

        $this->assertNotNull($payroll);
        $this->assertEquals(0, $payroll->total_work_hours);
        $this->assertEquals(0, $payroll->total_required_hours);
        $this->assertEquals(0, $payroll->final_total);
    }

    /**
     * Test job updates existing payroll if it exists.
     */
    public function test_job_updates_existing_payroll_if_it_exists(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'hourly_wage' => 100000,
        ]);

        $fromDate = Carbon::parse('2024-11-01');
        $toDate = Carbon::parse('2024-11-30');

        // Create existing payroll
        $existingPayroll = WorkerMonthlyPayroll::factory()->create([
            'employee_id' => $employee->id,
            'month' => 11,
            'year' => 2024,
            'total_work_hours' => 100.0,
        ]);

        // Create new approved report
        WorkerDailyReport::factory()->create([
            'employee_id' => $employee->id,
            'date' => Carbon::parse('2024-11-15'),
            'scheduled_hours' => 8.0,
            'actual_work_hours' => 8.0,
            'overtime_hours' => 0.0,
            'status' => 'approved',
        ]);

        $job = new GenerateMonthlyPayrollJob($fromDate, $toDate);
        app()->call([$job, 'handle']);

        $existingPayroll->refresh();
        $this->assertEquals(8.0, $existingPayroll->total_work_hours);
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
            'hourly_wage' => null, // Invalid wage
        ]);

        $fromDate = Carbon::parse('2024-11-01');
        $toDate = Carbon::parse('2024-11-30');

        WorkerDailyReport::factory()->create([
            'employee_id' => $employee->id,
            'date' => Carbon::parse('2024-11-15'),
            'scheduled_hours' => 8.0,
            'actual_work_hours' => 8.0,
            'status' => 'approved',
        ]);

        $job = new GenerateMonthlyPayrollJob($fromDate, $toDate);
        
        // Should not throw exception
        app()->call([$job, 'handle']);

        Log::assertLogged('error', function ($message, $context) use ($employee, $fromDate, $toDate) {
            return str_contains($message, 'Error generating monthly payroll') &&
                   $context['employee_id'] === $employee->id;
        });
    }
}

