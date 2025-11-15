<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\WorkerDailyReport;
use App\Models\WorkerMonthlyPayroll;
use App\Services\WorkerWageCalculationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateMonthlyPayrollJob implements ShouldQueue
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
        public Carbon $fromDate,
        public Carbon $toDate,
        public ?int $employeeId = null
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(WorkerWageCalculationService $wageCalculationService): void
    {
        $employees = $this->employeeId 
            ? Employee::where('id', $this->employeeId)->get()
            : Employee::all();

        foreach ($employees as $employee) {
            try {
                // Get all approved daily reports for the date range
                $reports = WorkerDailyReport::where('employee_id', $employee->id)
                    ->where('status', 'approved')
                    ->whereBetween('date', [$this->fromDate, $this->toDate])
                    ->get();

                // Aggregate totals
                $totalWorkHours = $reports->sum('actual_work_hours');
                $totalRequiredHours = $reports->sum('scheduled_hours');
                $totalOvertimeHours = $reports->sum('overtime_hours');

                // Calculate wages
                $baseWageTotal = $wageCalculationService->calculateBaseWage($employee, $totalRequiredHours);
                $overtimeWageTotal = $wageCalculationService->calculateOvertimeWage($employee, $totalOvertimeHours);

                // Get month and year from date range (use first date)
                $month = $this->fromDate->month;
                $year = $this->fromDate->year;

                // Create or update monthly payroll
                WorkerMonthlyPayroll::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'month' => $month,
                        'year' => $year,
                    ],
                    [
                        'total_work_hours' => $totalWorkHours,
                        'total_required_hours' => $totalRequiredHours,
                        'total_overtime_hours' => $totalOvertimeHours,
                        'base_wage_total' => $baseWageTotal,
                        'overtime_wage_total' => $overtimeWageTotal,
                        'additions' => 0, // Can be set manually by admin
                        'deductions' => 0, // Can be set manually by admin
                        'final_total' => $baseWageTotal + $overtimeWageTotal,
                        'generated_at' => now(),
                    ]
                );

            } catch (\Exception $e) {
                Log::error('Error generating monthly payroll for employee', [
                    'employee_id' => $employee->id,
                    'from_date' => $this->fromDate->toDateString(),
                    'to_date' => $this->toDate->toDateString(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
