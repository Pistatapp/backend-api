<?php

namespace App\Traits;

use App\Models\GpsReport;
use App\Models\Tractor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * TractorWorkingTime Trait
 *
 * Automatically detects when tractors start and stop working by analyzing GPS speed data.
 * Uses configurable thresholds and caching for optimal performance.
 *
 * @package App\Traits
 */
trait TractorWorkingTime
{
    /**
     * The tractor associated with this device.
     */
    protected ?Tractor $tractor = null;

    // Configuration defaults
    private const DEFAULT_SPEED_THRESHOLD = 2; // km/h
    private const DEFAULT_WINDOW_SIZE = 3; // Reports to analyze
    private const DEFAULT_CACHE_TTL = 60; // minutes
    private const DEFAULT_SHORT_CACHE_TTL = 5; // minutes
    private const DEFAULT_QUERY_WINDOW_MINUTES = 30; // minutes
    private const DEFAULT_PERFORMANCE_THRESHOLD = 0.1; // seconds

    /**
     * Main entry point: Detect start and end points for tractor working time.
     *
     * This method analyzes GPS reports to automatically identify when a tractor
     * begins and ends its work day based on speed patterns.
     *
     * @param GpsReport $report The GPS report to analyze
     * @return void
     */
    private function detectStartEndPoints(GpsReport $report): void
    {
        $startTime = microtime(true);

        try {
            // Detect tractor on time first (status change from 0 to 1 after work start)
            // This should work regardless of work hours
            $this->detectTractorOnTime($report);

            // Skip processing if outside work hours
            if (!$this->isWithinWorkHours($report)) {
                return;
            }

            // Get cached detection status
            $detectionStatus = $this->getDetectionStatus($report);

            // Exit early if both points already detected
            if ($detectionStatus->start && $detectionStatus->end) {
                return;
            }

            // Load and analyze reports
            $reports = $this->loadReportsForAnalysis($report);
            $currentIndex = $this->findCurrentReportIndex($reports, $report);

            if ($currentIndex === false) {
                return;
            }

            // Detect start and end points
            $this->detectWorkingPoints($reports, $currentIndex, $report, $detectionStatus);

        } finally {
            $this->logPerformanceIfSlow($startTime, $report);
        }
    }

    /**
     * Detect when tractor becomes "on" (status changes from 0 to 1) for the first time
     * after the official work start time.
     *
     * This method ensures that having a task doesn't interfere with the detection process.
     *
     * @param GpsReport $report The GPS report to analyze
     * @return void
     */
    private function detectTractorOnTime(GpsReport $report): void
    {
        $dateString = $report->date_time->toDateString();
        $tractorId = $this->tractor->id;
        $cacheKey = "on_time_{$tractorId}_{$dateString}";

        // Check if tractor on time has already been detected for today
        if (Cache::has($cacheKey)) {
            return;
        }

        // Check if tractor on time already exists in database
        if ($this->hasTractorOnTimeForToday($report)) {
            Cache::put($cacheKey, true, now()->endOfDay());
            return;
        }

        // Only process if current report has status = 1 (tractor is on)
        if ($report->status !== 1) {
            return;
        }

        // Get the official work start time for today
        $startWorkTime = $this->getTractorStartWorkTimeForToday();

        // Only consider reports after the official work start time
        if ($report->date_time->lt($startWorkTime)) {
            return;
        }

        // Load recent reports to check for status transition from 0 to 1
        $reports = $this->loadReportsForTractorOnTimeDetection($report, $startWorkTime);

        if ($reports->isEmpty()) {
            return;
        }

        // Find the first report where status changed from 0 to 1 after work start time
        $tractorOnTimeReport = $this->findFirstTractorOnTimeReport($reports, $startWorkTime);

        if ($tractorOnTimeReport) {
            $this->markTractorOnTime($tractorOnTimeReport);
            Cache::put($cacheKey, true, now()->endOfDay());
        }
    }

    /**
     * Load reports for tractor on time detection.
     * This method loads reports from the start of work time to current time.
     *
     * @param GpsReport $report
     * @param Carbon $startWorkTime
     * @return \Illuminate\Support\Collection
     */
    private function loadReportsForTractorOnTimeDetection(GpsReport $report, Carbon $startWorkTime): \Illuminate\Support\Collection
    {
        return $this->device->reports()
            ->whereDate('date_time', $report->date_time->toDateString())
            ->where('date_time', '>=', $startWorkTime)
            ->where('date_time', '<=', $report->date_time)
            ->orderBy('date_time')
            ->select(['id', 'date_time', 'status'])
            ->get();
    }

    /**
     * Find the first report where tractor status changed from 0 to 1 after work start time.
     *
     * @param \Illuminate\Support\Collection $reports
     * @param Carbon $startWorkTime
     * @return object|null
     */
    private function findFirstTractorOnTimeReport($reports, Carbon $startWorkTime): ?object
    {
        $reports = $reports->values();

        for ($i = 1; $i < $reports->count(); $i++) {
            $prevReport = $reports[$i - 1];
            $currReport = $reports[$i];

            // Check for status transition from 0 to 1
            if ($prevReport->status === 0 && $currReport->status === 1) {
                return $currReport;
            }
        }

        // If no transition found, check if the first report after work start has status = 1
        $firstReport = $reports->first();
        if ($firstReport && $firstReport->status === 1) {
            return $firstReport;
        }

        return null;
    }

    /**
     * Mark a report as the tractor on time in the database.
     *
     * @param object $report
     * @return void
     */
    private function markTractorOnTime(object $report): void
    {
        if (isset($report->id)) {
            GpsReport::where('id', $report->id)->update(['on_time' => $report->date_time]);
        }
    }

    /**
     * Check if tractor on time has already been detected for today.
     *
     * @param GpsReport $report
     * @return bool
     */
    private function hasTractorOnTimeForToday(GpsReport $report): bool
    {
        $dateString = $report->date_time->toDateString();
        $cacheKey = "on_time_exists_{$this->tractor->id}_{$dateString}";

        return Cache::remember($cacheKey, now()->addMinutes($this->getCacheTTL()), function () use ($dateString) {
            return $this->device->reports()
                ->useWritePdo(false)
                ->whereDate('date_time', $dateString)
                ->whereNotNull('on_time')
                ->exists();
        });
    }

    /**
     * Get the current detection status for today.
     *
     * @param GpsReport $report
     * @return object Detection status with start/end flags
     */
    private function getDetectionStatus(GpsReport $report): object
    {
        $dateString = $report->date_time->toDateString();
        $tractorId = $this->tractor->id;
        $cacheKey = "tractor_points_{$tractorId}_{$dateString}";

        $status = Cache::get($cacheKey, ['start' => false, 'end' => false]);

        // Check database if not cached
        if (!$status['start']) {
            $status['start'] = $this->hasStartPointForToday($report);
        }

        if (!$status['end']) {
            $status['end'] = $this->hasEndPointForToday($report);
        }

        // Cache the updated status
        if ($status['start'] || $status['end']) {
            Cache::put($cacheKey, $status, now()->addMinutes($this->getCacheTTL()));
        }

        return (object) $status;
    }

    /**
     * Load reports needed for analysis with optimized query.
     *
     * @param GpsReport $report
     * @return \Illuminate\Support\Collection
     */
    private function loadReportsForAnalysis(GpsReport $report): \Illuminate\Support\Collection
    {
        $dateString = $report->date_time->toDateString();
        $tractorId = $this->tractor->id;
        $cacheKey = "tractor_reports_{$tractorId}_{$dateString}";

        return Cache::remember($cacheKey, now()->addMinutes($this->getShortCacheTTL()), function () use ($report) {
            return $this->queryReportsForDate($report->date_time->toDateString(), $report->date_time);
        });
    }

    /**
     * Query reports for a specific date with time window optimization.
     *
     * @param string $dateString
     * @param Carbon $currentTime
     * @return \Illuminate\Support\Collection
     */
    private function queryReportsForDate(string $dateString, Carbon $currentTime): \Illuminate\Support\Collection
    {
        $windowMinutes = $this->getQueryWindowMinutes();
        $startTime = Carbon::parse($dateString)->startOfDay();
        $endTime = Carbon::parse($dateString)->endOfDay();

        return $this->device->reports()
            ->whereBetween('date_time', [$startTime, $endTime])
            ->whereBetween('date_time', [
                $currentTime->copy()->subMinutes($windowMinutes),
                $currentTime->copy()->addMinutes($windowMinutes)
            ])
            ->orderBy('date_time')
            ->select(['id', 'date_time', 'speed', 'status'])
            ->get()
            ->map(function ($report) {
                return (object) [
                    'id' => $report->id,
                    'date_time' => $report->date_time,
                    'speed' => (int) $report->speed,
                    'status' => (int) $report->status
                ];
            });
    }

    /**
     * Find the index of the current report in the collection.
     *
     * @param \Illuminate\Support\Collection $reports
     * @param GpsReport $report
     * @return int|false
     */
    private function findCurrentReportIndex($reports, GpsReport $report)
    {
        // Add current report if not in collection
        if (!$reports->contains('id', $report->id)) {
            $reports->push($report);
            $reports = $reports->sortBy('date_time')->values();
        }

        return $reports->search(fn($item) => $item->id === $report->id);
    }

    /**
     * Detect start and end working points.
     *
     * @param \Illuminate\Support\Collection $reports
     * @param int $currentIndex
     * @param GpsReport $report
     * @param object $detectionStatus
     * @return void
     */
    private function detectWorkingPoints($reports, int $currentIndex, GpsReport $report, object $detectionStatus): void
    {
        $updated = false;

        // Detect start point
        if (!$detectionStatus->start && $currentIndex >= 1) {
            $updated = $this->detectStartPoint($reports, $detectionStatus) || $updated;
        }

        // Detect end point
        if (!$detectionStatus->end && $currentIndex >= $this->getWindowSize() - 1) {
            $updated = $this->detectEndPoint($reports, $currentIndex, $report, $detectionStatus) || $updated;
        }

        // Update cache if changes were made
        if ($updated) {
            $this->updateDetectionStatusCache($report, $detectionStatus);
        }
    }

    /**
     * Detect a start point in the reports.
     *
     * Looks for a pattern where the tractor transitions from stationary to moving
     * and maintains movement for the configured window size.
     *
     * @param \Illuminate\Support\Collection $reports
     * @param object $detectionStatus
     * @return bool True if start point was detected
     */
    private function detectStartPoint($reports, object $detectionStatus): bool
    {
        $count = $reports->count();
        $windowSize = $this->getWindowSize();
        $speedThreshold = $this->getSpeedThreshold();

        if ($count < $windowSize) {
            return false;
        }

        $reports = $reports->values();
        $startWorkTime = $this->getTractorStartWorkTimeForToday();

        for ($i = 1; $i < $count; $i++) {
            $prevReport = $reports[$i - 1];
            $currReport = $reports[$i];

            // Check for speed transition from stopped to moving
            if ($this->isSpeedTransition($prevReport->speed, $currReport->speed, $speedThreshold)) {

                // Skip if before work hours
                if ($currReport->date_time->lt($startWorkTime)) {
                    continue;
                }

                // Verify sustained movement
                if ($this->hasSustainedMovement($reports, $i, $windowSize, $speedThreshold)) {
                    $this->markAsStartPoint($currReport);
                    $detectionStatus->start = true;
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect an end point in the reports.
     *
     * Looks for a pattern where the tractor transitions from moving to stationary
     * after a period of sustained movement.
     *
     * @param \Illuminate\Support\Collection $reports
     * @param int $currentIndex
     * @param GpsReport $report
     * @param object $detectionStatus
     * @return bool True if end point was detected
     */
    private function detectEndPoint($reports, int $currentIndex, GpsReport $report, object $detectionStatus): bool
    {
        $windowSize = $this->getWindowSize();
        $speedThreshold = $this->getSpeedThreshold();

        $window = $reports->slice($currentIndex - $windowSize + 1, $windowSize)->values();

        if ($window->count() < $windowSize) {
            return false;
        }

        // Check if this is an end point pattern
        if ($this->isEndPointPattern($window, $speedThreshold)) {
            $this->markAsEndPoint($report);
            $detectionStatus->end = true;
            return true;
        }

        return false;
    }

    /**
     * Check if there's a speed transition from stopped to moving.
     *
     * @param int $prevSpeed
     * @param int $currSpeed
     * @param int $threshold
     * @return bool
     */
    private function isSpeedTransition(int $prevSpeed, int $currSpeed, int $threshold): bool
    {
        return $prevSpeed < $threshold && $currSpeed >= $threshold;
    }

    /**
     * Check if movement is sustained for the required window size.
     *
     * @param \Illuminate\Support\Collection $reports
     * @param int $startIndex
     * @param int $windowSize
     * @param int $speedThreshold
     * @return bool
     */
    private function hasSustainedMovement($reports, int $startIndex, int $windowSize, int $speedThreshold): bool
    {
        for ($j = 1; $j < $windowSize && ($startIndex + $j) < $reports->count(); $j++) {
            if ($reports[$startIndex + $j]->speed < $speedThreshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the window represents an end point pattern.
     *
     * @param \Illuminate\Support\Collection $window
     * @param int $speedThreshold
     * @return bool
     */
    private function isEndPointPattern($window, int $speedThreshold): bool
    {
        $windowSize = $window->count();

        // Check that previous reports show movement
        for ($i = 0; $i < $windowSize - 1; $i++) {
            if ($window[$i]->speed < $speedThreshold) {
                return false;
            }
        }

        // Check that the last report shows stopping
        return $window->last()->speed < $speedThreshold;
    }

    /**
     * Mark a report as a start point in the database.
     *
     * @param object $report
     * @return void
     */
    private function markAsStartPoint(object $report): void
    {
        if (isset($report->id)) {
            GpsReport::where('id', $report->id)->update(['is_starting_point' => true]);
        }
    }

    /**
     * Mark a report as an end point in the database.
     *
     * @param GpsReport $report
     * @return void
     */
    private function markAsEndPoint(GpsReport $report): void
    {
        if (isset($report->id)) {
            GpsReport::where('id', $report->id)->update(['is_ending_point' => true]);
        }
    }

    /**
     * Update the detection status cache.
     *
     * @param GpsReport $report
     * @param object $detectionStatus
     * @return void
     */
    private function updateDetectionStatusCache(GpsReport $report, object $detectionStatus): void
    {
        $dateString = $report->date_time->toDateString();
        $tractorId = $this->tractor->id;
        $cacheKey = "tractor_points_{$tractorId}_{$dateString}";

        Cache::put($cacheKey, (array) $detectionStatus, now()->addMinutes($this->getCacheTTL()));
    }

    /**
     * Check if report is within work hours for early termination.
     *
     * @param GpsReport $report
     * @return bool
     */
    private function isWithinWorkHours(GpsReport $report): bool
    {
        $startWorkTime = $this->getTractorStartWorkTimeForToday();
        $endWorkTime = $this->getTractorEndWorkTimeForToday();

        return $report->date_time->gte($startWorkTime) && $report->date_time->lte($endWorkTime);
    }

    /**
     * Check if the report's date_time is within working hours.
     *
     * This method is called by ReportProcessingService and needs to handle both
     * GpsReport objects and array data.
     *
     * @param GpsReport|array $report
     * @return bool
     */
    private function isWithinWorkingHours(GpsReport|array $report): bool
    {
        $dateTime = is_array($report) ? $report['date_time'] : $report->date_time;
        $cacheKey = "tractor_working_hours_{$this->tractor->id}_{$dateTime->toDateString()}";

        $workingHours = Cache::remember($cacheKey, now()->endOfDay(), function () use ($dateTime) {
            return [
                'start' => $dateTime->copy()->setTimeFromTimeString($this->tractor->start_work_time),
                'end' => $dateTime->copy()->setTimeFromTimeString($this->tractor->end_work_time)
            ];
        });

        // Handle case where end time is before start time (crosses midnight)
        if ($workingHours['end']->lt($workingHours['start'])) {
            return $dateTime->gte($workingHours['start']) || $dateTime->lte($workingHours['end']);
        }

        return $dateTime->gte($workingHours['start']) && $dateTime->lte($workingHours['end']);
    }

    /**
     * Check if there's already a start point for today.
     *
     * @param GpsReport $report
     * @return bool
     */
    private function hasStartPointForToday(GpsReport $report): bool
    {
        $dateString = $report->date_time->toDateString();
        $cacheKey = "start_point_{$this->tractor->id}_{$dateString}";

        return Cache::remember($cacheKey, now()->addMinutes($this->getCacheTTL()), function () use ($dateString) {
            return $this->device->reports()
                ->useWritePdo(false)
                ->whereDate('date_time', $dateString)
                ->where('is_starting_point', true)
                ->exists();
        });
    }

    /**
     * Check if there's already an end point for today.
     *
     * @param GpsReport $report
     * @return bool
     */
    private function hasEndPointForToday(GpsReport $report): bool
    {
        $dateString = $report->date_time->toDateString();
        $cacheKey = "end_point_{$this->tractor->id}_{$dateString}";

        return Cache::remember($cacheKey, now()->addMinutes($this->getCacheTTL()), function () use ($dateString) {
            return $this->device->reports()
                ->useWritePdo(false)
                ->whereDate('date_time', $dateString)
                ->where('is_ending_point', true)
                ->exists();
        });
    }

    /**
     * Get the tractor's start work time for today.
     *
     * @return Carbon
     */
    private function getTractorStartWorkTimeForToday(): Carbon
    {
        $cacheKey = "tractor_start_work_time_{$this->tractor->id}";

        return Cache::remember($cacheKey, now()->endOfDay(), function () {
            return now()->setTimeFromTimeString($this->tractor->start_work_time);
        });
    }

    /**
     * Get the tractor's end work time for today.
     *
     * @return Carbon
     */
    private function getTractorEndWorkTimeForToday(): Carbon
    {
        $cacheKey = "tractor_end_work_time_{$this->tractor->id}";

        return Cache::remember($cacheKey, now()->endOfDay(), function () {
            return now()->setTimeFromTimeString($this->tractor->end_work_time);
        });
    }

    /**
     * Log performance metrics if operation was slow.
     *
     * @param float $startTime
     * @param GpsReport $report
     * @return void
     */
    private function logPerformanceIfSlow(float $startTime, GpsReport $report): void
    {
        $duration = microtime(true) - $startTime;

        if ($duration > $this->getPerformanceThreshold()) {
            Log::warning('Slow working time detection', [
                'tractor_id' => $this->tractor->id ?? 'unknown',
                'report_id' => $report->id ?? 'unknown',
                'duration' => round($duration, 4),
                'threshold' => $this->getPerformanceThreshold()
            ]);
        }
    }

    // Configuration Methods

    /**
     * Get configuration value with fallback to default.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getConfig(string $key, mixed $default): mixed
    {
        return config("gps.{$key}", $default);
    }

    /**
     * Get speed threshold from configuration.
     *
     * @return int Speed threshold in km/h
     */
    private function getSpeedThreshold(): int
    {
        return $this->getConfig('speed_threshold', self::DEFAULT_SPEED_THRESHOLD);
    }

    /**
     * Get window size from configuration.
     *
     * @return int Number of reports to analyze
     */
    private function getWindowSize(): int
    {
        return $this->getConfig('window_size', self::DEFAULT_WINDOW_SIZE);
    }

    /**
     * Get cache TTL from configuration.
     *
     * @return int Cache TTL in minutes
     */
    private function getCacheTTL(): int
    {
        return $this->getConfig('cache_ttl', self::DEFAULT_CACHE_TTL);
    }

    /**
     * Get short cache TTL from configuration.
     *
     * @return int Short cache TTL in minutes
     */
    private function getShortCacheTTL(): int
    {
        return $this->getConfig('short_cache_ttl', self::DEFAULT_SHORT_CACHE_TTL);
    }

    /**
     * Get query window minutes from configuration.
     *
     * @return int Query window in minutes
     */
    private function getQueryWindowMinutes(): int
    {
        return $this->getConfig('query_window_minutes', self::DEFAULT_QUERY_WINDOW_MINUTES);
    }

    /**
     * Get performance threshold from configuration.
     *
     * @return float Performance threshold in seconds
     */
    private function getPerformanceThreshold(): float
    {
        return $this->getConfig('performance_threshold', self::DEFAULT_PERFORMANCE_THRESHOLD);
    }
}
