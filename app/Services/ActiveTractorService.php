<?php

namespace App\Services;

use App\Models\Tractor;
use App\Models\TractorEfficiencyChart;
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
                'task-based' => 0,
            ],
            'driver' => new DriverResource($tractor->driver)
        ];
    }

    /**
     * Get weekly efficiency chart data for both total and task-based metrics.
     * Loads data from tractor_efficiency_charts table.
     *
     * @param Tractor $tractor
     * @return array
     */
    public function getWeeklyEfficiencyChart(Tractor $tractor): array
    {
        // Get the past 7 days excluding current day
        $endDate = Carbon::yesterday(); // Exclude current day
        $startDate = $endDate->copy()->subDays(6);

        // Load efficiency chart data from database
        $efficiencyCharts = TractorEfficiencyChart::where('tractor_id', $tractor->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('date')
            ->get();

        // Create a map of date => efficiency data for quick lookup
        $efficiencyMap = $efficiencyCharts->keyBy(function ($chart) {
            return $chart->date->toDateString();
        });

        $totalEfficiencies = [];
        $taskBasedEfficiencies = [];

        // Process each day
        for ($i = 0; $i < 7; $i++) {
            $currentDate = $startDate->copy()->addDays($i);
            $shamsiDate = jdate($currentDate)->format('Y/m/d');
            $dateString = $currentDate->toDateString();

            // Get efficiency data from database
            $chartData = $efficiencyMap->get($dateString);

            if ($chartData) {
                $totalEfficiencies[] = [
                    'efficiency' => number_format((float) $chartData->total_efficiency, 2),
                    'date' => $shamsiDate
                ];
                $taskBasedEfficiencies[] = [
                    'efficiency' => number_format((float) $chartData->task_based_efficiency, 2),
                    'date' => $shamsiDate
                ];
            } else {
                // No data for this day - use default values
                $totalEfficiencies[] = [
                    'efficiency' => '0.00',
                    'date' => $shamsiDate
                ];
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
