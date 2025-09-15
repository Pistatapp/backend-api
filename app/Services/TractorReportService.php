<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use App\Http\Resources\PointsResource;
use App\Http\Resources\TractorTaskResource;
use App\Http\Resources\DriverResource;

use App\Services\CacheService;
use App\Services\ChunkedDatabaseOperations;
/**
 * Service for generating tractor reports with optimized performance.
 *
 * Provides daily reports, tractor details, and path data with efficient
 * database queries and caching strategies.
 */
class TractorReportService
{
    private const DEFAULT_TIME_FORMAT = 'H:i:s';
    private const DEFAULT_DECIMAL_PLACES = 2;
    private const EFFICIENCY_HISTORY_DAYS = 7;

    public function __construct(
        private readonly KalmanFilter $kalmanFilter
    ) {}

    /**
     * Retrieves the tractor path for a specific date.
     */
    public function getTractorPath(Tractor $tractor, Carbon $date)
    {
        return $this->getFilteredReports($tractor, $date);
    }

    /**
     * Returns tractor details and summary data with comprehensive information.
     */
    public function getTractorDetails(Tractor $tractor, Carbon $date): array
    {
        // Eager load required relationships
        $tractor->load(['driver']);

        // Fetch data efficiently
        [$dailyReport, $reports, $currentTask, $efficiencyHistory] = $this->fetchTractorDetailsData($tractor, $date);

        $lastReport = $reports->last();
        $averageSpeed = $reports->avg('speed') ?? 0;

        // Get working times for the specific date
        $startWorkingTime = $tractor->getStartWorkingTime($date);
        $endWorkingTime = $tractor->getEndWorkingTime($date);
        $onTime = $tractor->getOnTime($date);

        return [
            'id' => $tractor->id,
            'name' => $tractor->name,
            'speed' => (int) $averageSpeed,
            'status' => $lastReport?->status ?? 0,
            'start_working_time' => $this->formatWorkingTime($startWorkingTime),
            'end_working_time' => $this->formatWorkingTime($endWorkingTime),
            'on_time' => $this->formatWorkingTime($onTime),
            'traveled_distance' => $this->formatDistance($dailyReport?->traveled_distance),
            'work_duration' => $this->formatDuration($dailyReport?->work_duration),
            'stoppage_count' => $dailyReport?->stoppage_count ?? 0,
            'stoppage_duration' => $this->formatDuration($dailyReport?->stoppage_duration),
            'efficiency' => $this->formatEfficiency($dailyReport?->efficiency),
            'current_task' => $currentTask ? new TractorTaskResource($currentTask) : null,
            'last_seven_days_efficiency' => $efficiencyHistory,
            'driver' => $tractor->driver ? new DriverResource($tractor->driver) : null
        ];
    }

    /**
     * Filters the tractor's GPS reports using the Kalman filter.
     */
    private function getFilteredReports(Tractor $tractor, Carbon $date)
    {
        // Use chunked processing for memory efficiency with large datasets
        $chunkedOps = new ChunkedDatabaseOperations();
        $reports = collect();

        $tractor->gpsReports()
            ->whereDate('date_time', $date)
            ->orderBy('date_time')
            ->chunk(1000, function ($chunk) use (&$reports) {
                $filteredChunk = $chunk->map(fn($report) => $this->applyKalmanFilter($report));
                $reports = $reports->merge($filteredChunk);
            });

        return PointsResource::collection($reports);
    }

    /**
     * Gets the tractor's current task with optimized query.
     */
    private function getCurrentTask(Tractor $tractor)
    {
        return $tractor->tasks()
            ->with(['operation', 'taskable', 'creator'])
            ->started()
            ->first();
    }

    /**
     * Fetch data for tractor details with optimized queries and caching.
     */
    private function fetchTractorDetailsData(Tractor $tractor, Carbon $date): array
    {
        // Use CacheService for daily report caching
        $device = $tractor->gpsDevice;
        if ($device) {
            $cacheService = new CacheService($device);
            $dailyMetrics = $cacheService->getDailyMetrics($date->format('Y-m-d'));
            $dailyReport = $dailyMetrics ? $tractor->gpsMetricsCalculations()->where('date', $date)->first() : null;
        } else {
            $dailyReport = $tractor->gpsMetricsCalculations()->where('date', $date)->first();
        }

        // Use chunked processing for memory efficiency
        $reports = collect();
        $tractor->gpsReports()
            ->whereDate('date_time', $date)
            ->orderBy('date_time')
            ->chunk(1000, function ($chunk) use (&$reports) {
                $filteredChunk = $chunk->map(fn($report) => $this->applyKalmanFilter($report));
                $reports = $reports->merge($filteredChunk);
            });
        $currentTask = $this->getCurrentTask($tractor);
        $efficiencyHistory = $this->getEfficiencyHistory($tractor, $date);

        return [$dailyReport, $reports, $currentTask, $efficiencyHistory];
    }

    /**
     * Get efficiency history for the last 7 days.
     */
    private function getEfficiencyHistory(Tractor $tractor, Carbon $date)
    {
        return $tractor->gpsMetricsCalculations()
            ->where('date', '<', $date)
            ->orderBy('date', 'desc')
            ->limit(self::EFFICIENCY_HISTORY_DAYS)
            ->get()
            ->map(fn($report) => [
                'date' => jdate($report->date)->format('Y/m/d'),
                'efficiency' => $this->formatEfficiency($report->efficiency)
            ]);
    }

    /**
     * Apply Kalman filter to a GPS report.
     */
    private function applyKalmanFilter($report)
    {
        $filtered = $this->kalmanFilter->filter($report->coordinate[0], $report->coordinate[1]);
        $report->coordinate = [$filtered['latitude'], $filtered['longitude']];
        return $report;
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
        return number_format($distance ?? 0, self::DEFAULT_DECIMAL_PLACES);
    }

    /**
     * Format duration in H:i:s format.
     */
    private function formatDuration(?int $duration): string
    {
        return gmdate(self::DEFAULT_TIME_FORMAT, $duration ?? 0);
    }

    /**
     * Format efficiency with consistent decimal places.
     */
    private function formatEfficiency(?float $efficiency): string
    {
        return number_format($efficiency ?? 0, self::DEFAULT_DECIMAL_PLACES);
    }
}
