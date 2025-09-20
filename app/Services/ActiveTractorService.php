<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use App\Http\Resources\DriverResource;
use Morilog\Jalali\Jalalian;

class ActiveTractorService
{
    private const DEFAULT_TIME_FORMAT = 'H:i:s';
    private const DEFAULT_DECIMAL_PLACES = 2;
    private const EFFICIENCY_HISTORY_DAYS = 7;

    public function __construct() {}


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

        // Get all GPS metrics calculations for the specified date
        $allMetrics = $tractor->gpsMetricsCalculations()->where('date', $date)->get();

        // Get daily metrics (where tractor_task_id is null)
        $dailyReport = $allMetrics->whereNull('tractor_task_id')->first();

        // Get task-based metrics (where tractor_task_id is not null)
        $taskMetrics = $allMetrics->whereNotNull('tractor_task_id');

        $latestStatus = $tractor->gpsReports()->latest('date_time')->first()->value('status');
        $averageSpeed = (int) $dailyReport?->average_speed;

        // Calculate efficiencies
        $efficiencies = $this->calculateEfficiencies($dailyReport, $taskMetrics);

        return [
            'id' => $tractor->id,
            'name' => $tractor->name,
            'speed' => $averageSpeed,
            'status' => $latestStatus,
            'traveled_distance' => $this->formatDistance($dailyReport?->traveled_distance),
            'work_duration' => $this->formatDuration($dailyReport?->work_duration),
            'stoppage_count' => $dailyReport?->stoppage_count,
            'stoppage_duration' => $this->formatDuration($dailyReport?->stoppage_duration),
            'efficiencies' => $efficiencies,
            'driver' => new DriverResource($tractor->driver)
        ];
    }

    /**
     * Get timings of a specific tractor
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return array
     */
    public function getTractorTimings(Tractor $tractor, Carbon $date)
    {
        $workingTimes = $tractor->getWorkingTimes($date);

        return [
            'start_working_time' => $this->formatWorkingTime($workingTimes['start_working_time']),
            'end_working_time' => $this->formatWorkingTime($workingTimes['end_working_time']),
            'on_time' => $this->formatWorkingTime($workingTimes['on_time']),
        ];
    }

    /**
     * Get weekly efficiency chart data for both total and task-based metrics.
     *
     * @param Tractor $tractor
     * @return array
     */
    public function getWeeklyEfficiencyChart(Tractor $tractor): array
    {
        // Get the last 7 days from today
        $endDate = Carbon::today();
        $startDate = $endDate->copy()->subDays(6);

        // Get all GPS metrics for the 7-day period
        $weeklyMetrics = $tractor->gpsMetricsCalculations()
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get()
            ->groupBy('date');

        $totalEfficiencies = [];
        $taskBasedEfficiencies = [];

        // Process each day
        for ($i = 0; $i < 7; $i++) {
            $currentDate = $startDate->copy()->addDays($i);
            $dateString = $currentDate->format('Y-m-d');
            $shamsiDate = $this->convertToShamsi($currentDate);

            $dayMetrics = $weeklyMetrics->get($dateString, collect());

            // Get daily metrics (total efficiency)
            $dailyMetric = $dayMetrics->whereNull('tractor_task_id')->first();
            $totalEfficiency = $dailyMetric ? $this->formatEfficiency($dailyMetric->efficiency) : '0.00';
            $totalEfficiencies[] = [
                'efficiency' => $totalEfficiency,
                'date' => $shamsiDate
            ];

            // Get task-based metrics
            $taskMetrics = $dayMetrics->whereNotNull('tractor_task_id');
            if ($taskMetrics->isNotEmpty()) {
                $averageTaskEfficiency = $taskMetrics->avg('efficiency');
                $taskBasedEfficiency = $this->formatEfficiency($averageTaskEfficiency);
            } else {
                $taskBasedEfficiency = '0.00';
            }

            $taskBasedEfficiencies[] = [
                'efficiency' => $taskBasedEfficiency,
                'date' => $shamsiDate
            ];
        }

        return [
            'total_efficiencies' => $totalEfficiencies,
            'task_based_efficiencies' => $taskBasedEfficiencies
        ];
    }


    /**
     * Format working time with consistent formatting.
     */
    private function formatWorkingTime($workingTime): ?string
    {
        return $workingTime?->date_time?->format(self::DEFAULT_TIME_FORMAT) ?? null;
    }

    /**
     * Format distance with consistent decimal places.
     */
    private function formatDistance(?float $distance): string
    {
        return number_format($distance, self::DEFAULT_DECIMAL_PLACES);
    }

    /**
     * Format duration in H:i:s format.
     */
    private function formatDuration(?int $duration): string
    {
        return gmdate(self::DEFAULT_TIME_FORMAT, $duration);
    }

    /**
     * Format efficiency with consistent decimal places.
     */
    private function formatEfficiency(?float $efficiency): string
    {
        return number_format($efficiency, self::DEFAULT_DECIMAL_PLACES);
    }

    /**
     * Calculate efficiencies for daily and task-based metrics.
     *
     * @param \App\Models\GpsMetricsCalculation|null $dailyReport
     * @param \Illuminate\Support\Collection $taskMetrics
     * @return array
     */
    private function calculateEfficiencies($dailyReport, $taskMetrics): array
    {
        $efficiencies = [
            'total' => $this->formatEfficiency($dailyReport?->efficiency),
            'task-based' => null
        ];

        // Calculate task-based efficiency
        if ($taskMetrics->isNotEmpty()) {
            if ($taskMetrics->count() === 1) {
                // If there's only one task metric, use its efficiency
                $efficiencies['task-based'] = $this->formatEfficiency($taskMetrics->first()->efficiency);
            } else {
                // If there are multiple task metrics, calculate average efficiency
                $averageEfficiency = $taskMetrics->avg('efficiency');
                $efficiencies['task-based'] = $this->formatEfficiency($averageEfficiency);
            }
        }

        return $efficiencies;
    }

    /**
     * Convert Gregorian date to Shamsi (Persian) date.
     *
     * @param Carbon $date
     * @return string
     */
    private function convertToShamsi(Carbon $date): string
    {
        return Jalalian::fromCarbon($date)->format('Y/m/d');
    }
}
