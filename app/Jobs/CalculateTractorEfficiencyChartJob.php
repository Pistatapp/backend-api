<?php

namespace App\Jobs;

use App\Models\Tractor;
use App\Models\TractorEfficiencyChart;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateTractorEfficiencyChartJob implements ShouldQueue
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
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Calculate efficiency for the previous day only
        $previousDay = Carbon::yesterday();

        // Process all tractors in chunks to avoid memory issues
        Tractor::chunk(100, function ($tractors) use ($previousDay) {
            foreach ($tractors as $tractor) {
                $this->calculateEfficiencyForDate($tractor, $previousDay);
            }
        });
    }

    /**
     * Calculate efficiency for a specific tractor on a specific date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return void
     */
    private function calculateEfficiencyForDate(
        Tractor $tractor,
        Carbon $date
    ): void {
        try {
            $totalEfficiency = $this->calculateTotalEfficiency($tractor, $date);
            $taskBasedEfficiency = $this->calculateTaskBasedEfficiency($tractor, $date);

            // Store or update the efficiency chart data
            TractorEfficiencyChart::updateOrCreate(
                [
                    'tractor_id' => $tractor->id,
                    'date' => $date->toDateString(),
                ],
                [
                    'total_efficiency' => $totalEfficiency,
                    'task_based_efficiency' => $taskBasedEfficiency,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Error calculating efficiency for tractor', [
                'tractor_id' => $tractor->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate total efficiency for a tractor on a date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return float
     */
    private function calculateTotalEfficiency(
        Tractor $tractor,
        Carbon $date
    ): float {
        $gpsDataAnalyzer = app(\App\Services\GpsDataAnalyzer::class);
        $results = $gpsDataAnalyzer->loadRecordsFor($tractor, $date)->analyzeLight();

        // Check if there's any data for this day
        if (empty($results['start_time'])) {
            return 0.00;
        }

        // Calculate total efficiency
        $workDurationSeconds = $results['movement_duration_seconds'];
        $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
        $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;
        $totalEfficiency = $expectedDailyWorkSeconds > 0 ? ($workDurationSeconds / $expectedDailyWorkSeconds) * 100 : 0;

        return round($totalEfficiency, 2);
    }

    /**
     * Calculate task-based efficiency for a tractor on a date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return float
     */
    private function calculateTaskBasedEfficiency(
        Tractor $tractor,
        Carbon $date
    ): float {
        $tractorTaskService = app(\App\Services\TractorTaskService::class);
        $tasks = $tractorTaskService->getAllTasksForDate($tractor, $date);

        if ($tasks->isEmpty()) {
            return 0.00;
        }

        $taskEfficiencies = [];
        $gpsDataAnalyzer = app(\App\Services\GpsDataAnalyzer::class);

        foreach ($tasks as $task) {
            // Get GPS metrics for this task
            $gpsData = $this->getGpsDataForTask($tractor, $task, $date);

            if ($gpsData->isEmpty()) {
                continue;
            }

            // Get task time window for analysis
            $taskDateTime = Carbon::parse($task->date);
            $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
            $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

            if ($taskEndDateTime->lt($taskStartDateTime)) {
                $taskEndDateTime->addDay();
            }

            // Calculate metrics with task time window
            $results = $gpsDataAnalyzer->loadFromRecords($gpsData)->analyzeLight($taskStartDateTime, $taskEndDateTime);

            if ($results['movement_duration_seconds'] > 0) {
                $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
                $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;
                $efficiency = $expectedDailyWorkSeconds > 0
                    ? ($results['movement_duration_seconds'] / $expectedDailyWorkSeconds) * 100
                    : 0;
                $taskEfficiencies[] = $efficiency;
            }
        }

        if (empty($taskEfficiencies)) {
            return 0.00;
        }

        $averageEfficiency = array_sum($taskEfficiencies) / count($taskEfficiencies);
        return round($averageEfficiency, 2);
    }

    /**
     * Get GPS data for a specific task (filtered by time range and zone).
     *
     * @param Tractor $tractor
     * @param \App\Models\TractorTask $task
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    private function getGpsDataForTask(Tractor $tractor, \App\Models\TractorTask $task, Carbon $date): \Illuminate\Support\Collection
    {
        $taskDateTime = Carbon::parse($task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        // Get GPS data for the tractor on the task date
        $gpsData = $tractor->gpsData()
            ->whereDate('date_time', $date)
            ->whereBetween('date_time', [$taskStartDateTime, $taskEndDateTime])
            ->orderBy('date_time')
            ->get();

        // Filter points that are in the task zone
        $tractorTaskService = app(\App\Services\TractorTaskService::class);
        $taskZone = $tractorTaskService->getTaskZone($task);

        if (!$taskZone) {
            return collect();
        }

        return $gpsData->filter(function ($point) use ($taskZone) {
            return is_point_in_polygon($point->coordinate, $taskZone);
        });
    }
}
