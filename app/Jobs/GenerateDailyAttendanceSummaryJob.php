<?php

namespace App\Jobs;

use App\Models\Labour;
use App\Models\LabourAttendanceSession;
use App\Models\LabourDailyReport;
use App\Models\LabourShiftSchedule;
use App\Services\LabourProductivityCalculator;
use App\Services\LabourWageCalculationService;
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
        LabourProductivityCalculator $productivityCalculator,
        LabourWageCalculationService $wageCalculationService
    ): void {
        // Get all labours
        $labours = Labour::all();

        foreach ($labours as $labour) {
            try {
                // Get attendance session for the date
                $session = LabourAttendanceSession::where('labour_id', $labour->id)
                    ->whereDate('date', $this->date)
                    ->first();

                if (!$session) {
                    // No attendance session - labour was absent
                    $this->createAbsentReport($labour, $this->date);
                    continue;
                }

                // Calculate work time from session (in hours)
                $actualWorkHours = ($session->total_in_zone_duration + $session->total_out_zone_duration) / 60;
                
                // Get required work time based on work type
                $requiredHours = $wageCalculationService->getRequiredHours($labour, $this->date);
                
                // Calculate overtime (if actual > required)
                $overtimeHours = max(0, $actualWorkHours - $requiredHours);
                
                // Time outside zone (in minutes, convert to hours for display)
                $timeOutsideZone = $session->total_out_zone_duration;
                
                // Calculate productivity score
                $productivityScore = $productivityCalculator->calculate($session);

                // Create or update daily report
                LabourDailyReport::updateOrCreate(
                    [
                        'labour_id' => $labour->id,
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
                Log::error('Error generating daily report for labour', [
                    'labour_id' => $labour->id,
                    'date' => $this->date->toDateString(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // TODO: Send notification to admin about pending reports
    }

    /**
     * Create absent report for labour
     *
     * @param Labour $labour
     * @param Carbon $date
     * @return void
     */
    private function createAbsentReport(Labour $labour, Carbon $date): void
    {
        $requiredHours = app(LabourWageCalculationService::class)->getRequiredHours($labour, $date);

        WorkerDailyReport::updateOrCreate(
            [
                'labour_id' => $labour->id,
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
