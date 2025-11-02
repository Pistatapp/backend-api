<?php

namespace App\Services;

use App\Models\Tractor;
use App\Models\TractorTask;
use App\Models\GpsMetricsCalculation;
use Carbon\Carbon;
use App\Http\Resources\DriverResource;
use App\Services\GpsDataAnalyzer;
use App\Services\TractorTaskService;

class ActiveTractorService
{
    public function __construct(
        private GpsDataAnalyzer $gpsDataAnalyzer,
        private TractorTaskService $tractorTaskService
    ) {}

    /**
     * Returns tractor performance.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return array
     */
    public function getTractorPerformance(Tractor $tractor, Carbon $date): array
    {
        $tractor->load(['driver']);

        $results = $this->gpsDataAnalyzer->loadRecordsFor($tractor, $date)->analyzeLight();

        $averageSpeed = $results['average_speed'];
        $latestStatus = $results['latest_status'];
        $stoppageCount = $results['stoppage_count'];

        // Calculate total efficiency
        $workDurationSeconds = $results['movement_duration_seconds'];
        $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8; // Default to 8 hours if not set
        $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;
        $totalEfficiency = $expectedDailyWorkSeconds > 0 ? ($workDurationSeconds / $expectedDailyWorkSeconds) * 100 : 0;

        // Calculate task-based efficiency
        $taskBasedEfficiency = $this->calculateTaskBasedEfficiency($tractor, $date);

        return [
            'id' => $tractor->id,
            'name' => $tractor->name,
            'on_time' => $results['device_on_time'],
            'start_working_time' => $results['first_movement_time'],
            'speed' => $averageSpeed,
            'status' => $latestStatus,
            'traveled_distance' => $results['movement_distance_km'],
            'work_duration' => $results['movement_duration_formatted'],
            'stoppage_count' => $stoppageCount,
            'stoppage_duration' => $results['stoppage_duration_formatted'],
            'stoppage_duration_while_on' => $results['stoppage_duration_while_on_formatted'],
            'stoppage_duration_while_off' => $results['stoppage_duration_while_off_formatted'],
            'efficiencies' => [
                'total' => number_format($totalEfficiency, 2),
                'task-based' => $taskBasedEfficiency
            ],
            'driver' => new DriverResource($tractor->driver)
        ];
    }

    /**
     * Get weekly efficiency chart data for both total and task-based metrics.
     * Uses GpsDataAnalyzer for real-time calculation of past 7 days (excluding current day).
     * Results are cached until the end of the day for performance.
     *
     * @param Tractor $tractor
     * @return array
     */
    public function getWeeklyEfficiencyChart(Tractor $tractor): array
    {
        // Create cache key based on tractor ID and date
        $cacheKey = "weekly_efficiency_chart_tractor_{$tractor->id}_" . Carbon::today()->format('Y-m-d');

        // Try to get cached results first
        $cachedResults = cache()->get($cacheKey);
        if ($cachedResults !== null) {
            return $cachedResults;
        }

        // Get the past 7 days excluding current day
        $endDate = Carbon::yesterday(); // Exclude current day
        $startDate = $endDate->copy()->subDays(6);

        // Pre-calculate expected daily work seconds for performance
        $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
        $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;

        $totalEfficiencies = [];
        $taskBasedEfficiencies = [];

        // Process each day using GpsDataAnalyzer for real-time calculation
        for ($i = 0; $i < 7; $i++) {
            $currentDate = $startDate->copy()->addDays($i);
            $shamsiDate = jdate($currentDate)->format('Y/m/d');

            // Analyze GPS data using GpsDataAnalyzer with working time window
            $results = $this->gpsDataAnalyzer->loadRecordsFor($tractor, $currentDate)->analyzeLight();

            // Check if there's any data for this day
            if (empty($results['start_time'])) {
                // No data for this day - use default values
                $totalEfficiencies[] = [
                    'efficiency' => '0.00',
                    'date' => $shamsiDate
                ];
                $taskBasedEfficiencies[] = [
                    'efficiency' => '0.00',
                    'date' => $shamsiDate
                ];
                continue;
            }

            // Calculate total efficiency
            $workDurationSeconds = $results['movement_duration_seconds'];
            $totalEfficiency = $expectedDailyWorkSeconds > 0 ? ($workDurationSeconds / $expectedDailyWorkSeconds) * 100 : 0;

            $totalEfficiencies[] = [
                'efficiency' => number_format($totalEfficiency, 2),
                'date' => $shamsiDate
            ];

            // Calculate task-based efficiency for this day
            $taskBasedEfficiency = $this->calculateTaskBasedEfficiencyForDate($tractor, $currentDate);
            $taskBasedEfficiencies[] = [
                'efficiency' => $taskBasedEfficiency,
                'date' => $shamsiDate
            ];
        }

        $results = [
            'total_efficiencies' => $totalEfficiencies,
            'task_based_efficiencies' => $taskBasedEfficiencies
        ];

        // Cache results until end of day
        $cacheUntil = Carbon::today()->endOfDay();
        $cacheTtl = $cacheUntil->diffInSeconds(Carbon::now());

        cache()->put($cacheKey, $results, $cacheTtl);

        return $results;
    }

    /**
     * Calculate task-based efficiency for a tractor on a specific date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return string
     */
    private function calculateTaskBasedEfficiency(Tractor $tractor, Carbon $date): string
    {
        $tasks = $this->tractorTaskService->getAllTasksForDate($tractor, $date);

        if ($tasks->isEmpty()) {
            return '0.00';
        }

        $taskEfficiencies = [];

        foreach ($tasks as $task) {
            $metrics = GpsMetricsCalculation::where('tractor_id', $tractor->id)
                ->where('tractor_task_id', $task->id)
                ->where('date', $date->toDateString())
                ->first();

            if ($metrics && $metrics->work_duration > 0) {
                $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
                $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;
                $efficiency = $expectedDailyWorkSeconds > 0 ? ($metrics->work_duration / $expectedDailyWorkSeconds) * 100 : 0;
                $taskEfficiencies[] = $efficiency;
            }
        }

        if (empty($taskEfficiencies)) {
            return '0.00';
        }

        $averageEfficiency = array_sum($taskEfficiencies) / count($taskEfficiencies);
        return number_format($averageEfficiency, 2);
    }

    /**
     * Calculate task-based efficiency for a specific date (used in weekly chart).
     * This method also ensures GPS metrics are calculated and persisted for each task.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return string
     */
    private function calculateTaskBasedEfficiencyForDate(Tractor $tractor, Carbon $date): string
    {
        $tasks = $this->tractorTaskService->getAllTasksForDate($tractor, $date);

        if ($tasks->isEmpty()) {
            return '0.00';
        }

        $taskEfficiencies = [];

        foreach ($tasks as $task) {
            // Get or create GPS metrics for this task
            $metrics = $this->getOrCreateTaskMetrics($tractor, $task, $date);

            if ($metrics && $metrics->work_duration > 0) {
                $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
                $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;
                $efficiency = $expectedDailyWorkSeconds > 0 ? ($metrics->work_duration / $expectedDailyWorkSeconds) * 100 : 0;
                $taskEfficiencies[] = $efficiency;
            }
        }

        if (empty($taskEfficiencies)) {
            return '0.00';
        }

        $averageEfficiency = array_sum($taskEfficiencies) / count($taskEfficiencies);
        return number_format($averageEfficiency, 2);
    }

    /**
     * Get or create GPS metrics for a specific task on a date.
     * If metrics don't exist, calculate them from GPS data.
     *
     * @param Tractor $tractor
     * @param TractorTask $task
     * @param Carbon $date
     * @return GpsMetricsCalculation|null
     */
    private function getOrCreateTaskMetrics(Tractor $tractor, TractorTask $task, Carbon $date): ?GpsMetricsCalculation
    {
        $dateString = $date->toDateString();

        // Try to get existing metrics
        $metrics = GpsMetricsCalculation::where('tractor_id', $tractor->id)
            ->where('tractor_task_id', $task->id)
            ->where('date', $dateString)
            ->first();

        if ($metrics) {
            return $metrics;
        }

        // Calculate metrics from GPS data if they don't exist
        $gpsData = $this->getGpsDataForTask($tractor, $task, $date);

        if ($gpsData->isEmpty()) {
            return null;
        }

        // Get task time window for analysis
        $taskDateTime = Carbon::parse($task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        // Use GpsDataAnalyzer to calculate metrics with task time window
        // Since we have pre-filtered GPS data by task zone and time, we use loadFromRecords
        // but pass task time window to analyze() to scope calculations
        $results = $this->gpsDataAnalyzer->loadFromRecords($gpsData)->analyzeLight($taskStartDateTime, $taskEndDateTime);

        // Create and save metrics record
        $metrics = GpsMetricsCalculation::create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $task->id,
            'date' => $dateString,
            'traveled_distance' => $results['movement_distance_km'],
            'work_duration' => $results['movement_duration_seconds'],
            'stoppage_count' => $results['stoppage_count'],
            'stoppage_duration' => $results['stoppage_duration_seconds'],
            'stoppage_duration_while_on' => $results['stoppage_duration_while_on_seconds'],
            'stoppage_duration_while_off' => $results['stoppage_duration_while_off_seconds'],
            'average_speed' => $results['average_speed'],
            'efficiency' => $this->calculateTaskEfficiency($tractor, $results['movement_duration_seconds']),
        ]);

        return $metrics;
    }

    /**
     * Get GPS data for a specific task (filtered by time range and zone).
     *
     * @param Tractor $tractor
     * @param TractorTask $task
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    private function getGpsDataForTask(Tractor $tractor, TractorTask $task, Carbon $date): \Illuminate\Support\Collection
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
        $taskZone = $this->tractorTaskService->getTaskZone($task);

        if (!$taskZone) {
            return collect();
        }

        return $gpsData->filter(function ($point) use ($taskZone) {
            return is_point_in_polygon($point->coordinate, $taskZone);
        });
    }

    /**
     * Calculate task efficiency based on work duration.
     *
     * @param Tractor $tractor
     * @param int $workDurationSeconds
     * @return float
     */
    private function calculateTaskEfficiency(Tractor $tractor, int $workDurationSeconds): float
    {
        $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
        $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;

        if ($expectedDailyWorkSeconds <= 0) {
            return 0;
        }

        return ($workDurationSeconds / $expectedDailyWorkSeconds) * 100;
    }
}
