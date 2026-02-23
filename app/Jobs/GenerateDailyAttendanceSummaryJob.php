<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\AttendanceSession;
use App\Models\AttendanceDailyReport;
use App\Services\AttendanceProductivityCalculator;
use App\Services\AttendanceWageCalculationService;
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

    public $tries = 3;

    public function __construct(
        public Carbon $date
    ) {}

    public function handle(
        AttendanceProductivityCalculator $productivityCalculator,
        AttendanceWageCalculationService $wageCalculationService
    ): void {
        $users = User::whereHas('attendanceTracking', fn ($q) => $q->where('enabled', true))->get();

        foreach ($users as $user) {
            try {
                $session = AttendanceSession::where('user_id', $user->id)
                    ->whereDate('date', $this->date)
                    ->first();

                if (! $session) {
                    $this->createAbsentReport($user, $this->date, $wageCalculationService);
                    continue;
                }

                $actualWorkHours = ($session->in_zone_duration + $session->outside_zone_duration) / 60;
                $requiredHours = $wageCalculationService->getRequiredHours($user, $this->date);
                $overtimeHours = max(0, $actualWorkHours - $requiredHours);
                $timeOutsideZone = $session->outside_zone_duration;
                $productivityScore = $productivityCalculator->calculate($session);

                AttendanceDailyReport::updateOrCreate(
                    [
                        'user_id' => $user->id,
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
                Log::error('Error generating daily report for user', [
                    'user_id' => $user->id,
                    'date' => $this->date->toDateString(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function createAbsentReport(User $user, Carbon $date, AttendanceWageCalculationService $wageCalculationService): void
    {
        $requiredHours = $wageCalculationService->getRequiredHours($user, $date);

        AttendanceDailyReport::updateOrCreate(
            [
                'user_id' => $user->id,
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
