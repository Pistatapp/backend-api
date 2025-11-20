<?php

namespace App\Services;

use App\Models\Tractor;
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
     * For past dates (date < current day), fetches data from GpsMetricsCalculation table.
     * For current day or future dates, calculates data from GPS records.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return array
     */
    public function getTractorPerformance(Tractor $tractor, Carbon $date): array
    {
        $tractor->load(['driver']);

        // Check if the date is in the past (before current day at start of day)
        // Use cached data for past dates, real-time analysis for today and future dates
        $isPastDate = $date->copy()->startOfDay()->isBefore(Carbon::today()->startOfDay());

        if ($isPastDate) {
            // Fetch data from GpsMetricsCalculation for past dates
            return $this->getTractorPerformanceFromCache($tractor, $date);
        }

        // For current day or future dates, use real-time GPS data analysis
        return $this->getTractorPerformanceFromGpsData($tractor, $date);
    }

    /**
     * Get tractor performance from cached GpsMetricsCalculation data.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return array
     */
    private function getTractorPerformanceFromCache(Tractor $tractor, Carbon $date): array
    {
        $dateString = $date->toDateString();

        // Query GpsMetricsCalculation for daily aggregate (tractor_task_id is null)
        $metrics = GpsMetricsCalculation::where('tractor_id', $tractor->id)
            ->where('date', $dateString)
            ->whereNull('tractor_task_id')
            ->first();

        // If no cached data exists, return default/empty values
        if (!$metrics) {
            return $this->getEmptyPerformanceData($tractor);
        }

        // Format durations using helper function
        $workDurationFormatted = to_time_format($metrics->work_duration);
        $stoppageDurationFormatted = to_time_format($metrics->stoppage_duration);
        $stoppageDurationWhileOnFormatted = to_time_format($metrics->stoppage_duration_while_on);
        $stoppageDurationWhileOffFormatted = to_time_format($metrics->stoppage_duration_while_off);

        // Calculate efficiency (always recalculate based on current expected_daily_work_time)
        // This ensures efficiency reflects any changes to expected_daily_work_time
        $totalEfficiency = $this->calculateEfficiency($tractor, $metrics->work_duration);

        // Calculate task-based efficiency from task records
        $taskBasedEfficiency = $this->getTaskBasedEfficiency($tractor, $date);

        return [
            'id' => $tractor->id,
            'name' => $tractor->name,
            'on_time' => $metrics->timings['device_on_time'], // Not stored in GpsMetricsCalculation
            'start_working_time' => $metrics->timings['first_movement_time'], // Not stored in GpsMetricsCalculation
            'speed' => $metrics->average_speed,
            'status' => 0, // Not stored in GpsMetricsCalculation, default to 0 (off)
            'traveled_distance' => $metrics->traveled_distance,
            'work_duration' => $workDurationFormatted,
            'stoppage_count' => $metrics->stoppage_count,
            'stoppage_duration' => $stoppageDurationFormatted,
            'stoppage_duration_while_on' => $stoppageDurationWhileOnFormatted,
            'stoppage_duration_while_off' => $stoppageDurationWhileOffFormatted,
            'efficiencies' => [
                'total' => number_format($totalEfficiency, 2),
                'task-based' => number_format($taskBasedEfficiency, 2),
            ],
            'driver' => new DriverResource($tractor->driver)
        ];
    }

    /**
     * Get tractor performance from real-time GPS data analysis.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return array
     */
    private function getTractorPerformanceFromGpsData(Tractor $tractor, Carbon $date): array
    {
        $results = $this->gpsDataAnalyzer->loadRecordsFor($tractor, $date)->analyzeLight();

        $averageSpeed = $results['average_speed'];
        $latestStatus = $results['latest_status'];
        $stoppageCount = $results['stoppage_count'];

        // Calculate total efficiency
        $workDurationSeconds = $results['movement_duration_seconds'];
        $totalEfficiency = $this->calculateEfficiency($tractor, $workDurationSeconds);

        // Calculate task-based efficiency from task records
        $taskBasedEfficiency = $this->getTaskBasedEfficiency($tractor, $date);

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
                'task-based' => number_format($taskBasedEfficiency, 2),
            ],
            'driver' => new DriverResource($tractor->driver)
        ];
    }

    /**
     * Calculate efficiency based on work duration and expected daily work time.
     *
     * @param Tractor $tractor
     * @param int $workDurationSeconds Work duration in seconds
     * @return float Efficiency percentage (0-100)
     */
    private function calculateEfficiency(Tractor $tractor, int $workDurationSeconds): float
    {
        $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
        $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;

        if ($expectedDailyWorkSeconds <= 0) {
            return 0;
        }

        return ($workDurationSeconds / $expectedDailyWorkSeconds) * 100;
    }

    /**
     * Get task-based efficiency as average of all task records for the given date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return float Average efficiency percentage (0-100)
     */
    private function getTaskBasedEfficiency(Tractor $tractor, Carbon $date): float
    {
        $dateString = $date->toDateString();

        // Get average efficiency from all task-based records (where tractor_task_id is not null)
        $averageEfficiency = GpsMetricsCalculation::where('tractor_id', $tractor->id)
            ->where('date', $dateString)
            ->whereNotNull('tractor_task_id')
            ->avg('efficiency');

        // If no task records exist, return 0
        return $averageEfficiency ? (float) $averageEfficiency : 0.0;
    }

    /**
     * Get empty performance data structure for when no cached data exists.
     *
     * @param Tractor $tractor
     * @return array
     */
    private function getEmptyPerformanceData(Tractor $tractor): array
    {
        return [
            'id' => $tractor->id,
            'name' => $tractor->name,
            'on_time' => null,
            'start_working_time' => null,
            'speed' => 0,
            'status' => 0,
            'traveled_distance' => 0,
            'work_duration' => '00:00:00',
            'stoppage_count' => 0,
            'stoppage_duration' => '00:00:00',
            'stoppage_duration_while_on' => '00:00:00',
            'stoppage_duration_while_off' => '00:00:00',
            'efficiencies' => [
                'total' => '0.00',
                'task-based' => '0.00',
            ],
            'driver' => new DriverResource($tractor->driver)
        ];
    }

    /**
     * Get weekly efficiency chart data for both total and task-based metrics.
     * Loads data from gps_metrics_calculations table.
     *
     * @param Tractor $tractor
     * @return array
     */
    public function getWeeklyEfficiencyChart(Tractor $tractor): array
    {
        // Get the past 7 days excluding current day
        $endDate = Carbon::yesterday(); // Exclude current day
        $startDate = $endDate->copy()->subDays(6);

        // Load total efficiency data (where tractor_task_id is null) from gps_metrics_calculations
        $totalEfficiencyRecords = GpsMetricsCalculation::where('tractor_id', $tractor->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNull('tractor_task_id')
            ->orderBy('date')
            ->get();

        // Load task-based efficiency data (where tractor_task_id is not null) from gps_metrics_calculations
        $taskEfficiencyRecords = GpsMetricsCalculation::where('tractor_id', $tractor->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotNull('tractor_task_id')
            ->get();

        // Create a map of date => total efficiency for quick lookup
        $totalEfficiencyMap = $totalEfficiencyRecords->keyBy(function ($record) {
            return $record->date->toDateString();
        });

        // Group task records by date and calculate average efficiency for each day
        $taskEfficiencyMap = $taskEfficiencyRecords->groupBy(function ($record) {
            return $record->date->toDateString();
        })->map(function ($records) {
            return $records->avg('efficiency');
        });

        $totalEfficiencies = [];
        $taskBasedEfficiencies = [];

        // Process each day
        for ($i = 0; $i < 7; $i++) {
            $currentDate = $startDate->copy()->addDays($i);
            $shamsiDate = jdate($currentDate)->format('Y/m/d');
            $dateString = $currentDate->toDateString();

            // Get total efficiency from record where tractor_task_id is null
            $totalRecord = $totalEfficiencyMap->get($dateString);
            if ($totalRecord) {
                $totalEfficiencies[] = [
                    'efficiency' => number_format((float) $totalRecord->efficiency, 2),
                    'date' => $shamsiDate
                ];
            } else {
                // No data for this day - use default value
                $totalEfficiencies[] = [
                    'efficiency' => '0.00',
                    'date' => $shamsiDate
                ];
            }

            // Get average efficiency from task records for this day
            $averageTaskEfficiency = $taskEfficiencyMap->get($dateString);
            if ($averageTaskEfficiency !== null) {
                $taskBasedEfficiencies[] = [
                    'efficiency' => number_format((float) $averageTaskEfficiency, 2),
                    'date' => $shamsiDate
                ];
            } else {
                // No task data for this day - use default value
                $taskBasedEfficiencies[] = [
                    'efficiency' => '0.00',
                    'date' => $shamsiDate
                ];
            }
        }

        return [
            'total_efficiencies' => $totalEfficiencies,
            'task_based_efficiencies' => $taskBasedEfficiencies
        ];
    }
}
