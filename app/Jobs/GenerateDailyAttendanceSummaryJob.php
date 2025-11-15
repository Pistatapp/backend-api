<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\WorkerAttendanceSession;
use App\Models\WorkerDailyReport;
use App\Models\WorkerShiftSchedule;
use App\Services\WorkerProductivityCalculator;
use App\Services\WorkerWageCalculationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateDailyAttendanceSummaryJob implements ShouldQueue
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
        public Carbon $date
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(
        WorkerProductivityCalculator $productivityCalculator,
        WorkerWageCalculationService $wageCalculationService
    ): void {
        // Get all employees
        $employees = Employee::all();

        foreach ($employees as $employee) {
            try {
                // Get attendance session for the date
                $session = WorkerAttendanceSession::where('employee_id', $employee->id)
                    ->whereDate('date', $this->date)
                    ->first();

                if (!$session) {
                    // No attendance session - worker was absent
                    $this->createAbsentReport($employee, $this->date);
                    continue;
                }

                // Calculate work time from session (in hours)
                $actualWorkHours = ($session->total_in_zone_duration + $session->total_out_zone_duration) / 60;
                
                // Get required work time based on work type
                $requiredHours = $wageCalculationService->getRequiredHours($employee, $this->date);
                
                // Calculate overtime (if actual > required)
                $overtimeHours = max(0, $actualWorkHours - $requiredHours);
                
                // Time outside zone (in minutes, convert to hours for display)
                $timeOutsideZone = $session->total_out_zone_duration;
                
                // Calculate productivity score
                $productivityScore = $productivityCalculator->calculate($session);

                // Create or update daily report
                WorkerDailyReport::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'date' => $this->date,
                    ],
                    [
                        'scheduled_hours' => $requiredHours,
                        'actual_work_hours' => $actualWorkHours,
                        'overtime_hours' => $overtimeHours,
                        'time_outside_zone' => $timeOutsideZone,
                        'productivity_score' => $productivityScore,
                        'status' => 'pending',
                    ]
                );

            } catch (\Exception $e) {
                Log::error('Error generating daily report for employee', [
                    'employee_id' => $employee->id,
                    'date' => $this->date->toDateString(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // TODO: Send notification to admin about pending reports
    }

    /**
     * Create absent report for employee
     *
     * @param Employee $employee
     * @param Carbon $date
     * @return void
     */
    private function createAbsentReport(Employee $employee, Carbon $date): void
    {
        $requiredHours = app(WorkerWageCalculationService::class)->getRequiredHours($employee, $date);

        WorkerDailyReport::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'date' => $date,
            ],
            [
                'scheduled_hours' => $requiredHours,
                'actual_work_hours' => 0,
                'overtime_hours' => 0,
                'time_outside_zone' => 0,
                'productivity_score' => null,
                'status' => 'pending',
            ]
        );
    }
}
