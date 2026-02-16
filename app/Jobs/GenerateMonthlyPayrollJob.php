<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\AttendanceDailyReport;
use App\Models\AttendanceMonthlyPayroll;
use App\Services\AttendanceWageCalculationService;
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

    public $tries = 3;

    public function __construct(
        public Carbon $fromDate,
        public Carbon $toDate,
        public ?int $userId = null
    ) {}

    public function handle(AttendanceWageCalculationService $wageCalculationService): void
    {
        $users = $this->userId
            ? User::where('id', $this->userId)->whereHas('attendanceTracking')->get()
            : User::whereHas('attendanceTracking')->get();

        foreach ($users as $user) {
            try {
                $reports = AttendanceDailyReport::where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->whereBetween('date', [$this->fromDate, $this->toDate])
                    ->get();

                $totalWorkHours = $reports->sum('actual_work_hours');
                $totalRequiredHours = $reports->sum('scheduled_hours');
                $totalOvertimeHours = $reports->sum('overtime_hours');

                $baseWageTotal = $wageCalculationService->calculateBaseWage($user, $totalRequiredHours);
                $overtimeWageTotal = $wageCalculationService->calculateOvertimeWage($user, $totalOvertimeHours);

                $month = $this->fromDate->month;
                $year = $this->fromDate->year;

                AttendanceMonthlyPayroll::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'month' => $month,
                        'year' => $year,
                    ],
                    [
                        'total_work_hours' => $totalWorkHours,
                        'total_required_hours' => $totalRequiredHours,
                        'total_overtime_hours' => $totalOvertimeHours,
                        'base_wage_total' => $baseWageTotal,
                        'overtime_wage_total' => $overtimeWageTotal,
                        'additions' => 0,
                        'deductions' => 0,
                        'final_total' => $baseWageTotal + $overtimeWageTotal,
                        'generated_at' => now(),
                    ]
                );
            } catch (\Exception $e) {
                Log::error('Error generating monthly payroll for user', [
                    'user_id' => $user->id,
                    'from_date' => $this->fromDate->toDateString(),
                    'to_date' => $this->toDate->toDateString(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
