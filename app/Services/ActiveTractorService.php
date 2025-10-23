<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use App\Http\Resources\DriverResource;
use Morilog\Jalali\Jalalian;
use App\Models\Farm;
use Illuminate\Support\Collection;
use App\Services\GpsDataAnalyzer;
use App\Services\Tractor\TractorWorkTimeDetectionService;

class ActiveTractorService
{
    private const DEFAULT_TIME_FORMAT = 'H:i:s';
    private const DEFAULT_DECIMAL_PLACES = 2;
    private const EFFICIENCY_HISTORY_DAYS = 7;

    public function __construct(
        private GpsDataAnalyzer $gpsDataAnalyzer,
        private TractorWorkTimeDetectionService $tractorWorkTimeDetectionService
    ) {}

    public function getActiveTractors(Farm $farm): Collection
    {
        $tractors = $farm->tractors()->active()->get();

        return $tractors;
    }

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

        $gpsData = $tractor->gpsData()->whereDate('date_time', $date)->get();

        // Get working time boundaries from tractor
        $workingStartTime = null;
        $workingEndTime = null;

        if ($tractor->start_work_time && $tractor->end_work_time) {
            $workingStartTime = Carbon::parse($date->toDateString() . ' ' . $tractor->start_work_time);
            $workingEndTime = Carbon::parse($date->toDateString() . ' ' . $tractor->end_work_time);
        }

        $gpsDataAnalyzer = $this->gpsDataAnalyzer->loadFromRecords($gpsData);

        // Set working time boundaries if they exist
        if ($workingStartTime && $workingEndTime) {
            $gpsDataAnalyzer->setWorkingTimeBoundaries($workingStartTime, $workingEndTime);
        }

        $results = $gpsDataAnalyzer->analyze();

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
                'total' => round($totalEfficiency, 2),
                'task-based' => null
            ],
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
        // Use the new work time detection service
        $workTimes = $this->tractorWorkTimeDetectionService->detectWorkTimes($tractor, $date);

        return [
            'start_working_time' => $workTimes['start_work_time'],
            'end_working_time' => $workTimes['end_work_time'],
            'on_time' => $workTimes['on_time'],
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

        // Batch fetch all GPS data for the 7-day period to reduce database queries
        $allGpsData = $tractor->gpsData()
            ->whereBetween('date_time', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->orderBy('date_time')
            ->get()
            ->groupBy(function ($item) {
                return $item->date_time->format('Y-m-d');
            });

        $totalEfficiencies = [];
        $taskBasedEfficiencies = [];

        // Process each day using GpsDataAnalyzer for real-time calculation
        for ($i = 0; $i < 7; $i++) {
            $currentDate = $startDate->copy()->addDays($i);
            $dateString = $currentDate->format('Y-m-d');
            $shamsiDate = $this->convertToShamsi($currentDate);

            // Get GPS data for the specific day from batched data
            $gpsData = $allGpsData->get($dateString, collect());

            if ($gpsData->isEmpty()) {
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

            // Analyze GPS data using GpsDataAnalyzer
            $gpsDataAnalyzer = $this->gpsDataAnalyzer->loadFromRecords($gpsData);

            // Set working time boundaries if they exist
            if ($tractor->start_work_time && $tractor->end_work_time) {
                $workingStartTime = Carbon::parse($dateString . ' ' . $tractor->start_work_time);
                $workingEndTime = Carbon::parse($dateString . ' ' . $tractor->end_work_time);
                $gpsDataAnalyzer->setWorkingTimeBoundaries($workingStartTime, $workingEndTime);
            }

            $results = $gpsDataAnalyzer->analyze();

            // Calculate total efficiency
            $workDurationSeconds = $results['movement_duration_seconds'];
            $totalEfficiency = $expectedDailyWorkSeconds > 0 ? ($workDurationSeconds / $expectedDailyWorkSeconds) * 100 : 0;

            $totalEfficiencies[] = [
                'efficiency' => $this->formatEfficiency($totalEfficiency),
                'date' => $shamsiDate
            ];

            // Task-based efficiency is set to null as requested
            $taskBasedEfficiencies[] = [
                'efficiency' => '0.00', // Set to null or calculate if needed
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
     * Format efficiency with consistent decimal places.
     */
    private function formatEfficiency(?float $efficiency): string
    {
        return number_format($efficiency, self::DEFAULT_DECIMAL_PLACES);
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
