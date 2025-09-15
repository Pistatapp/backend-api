<?php

namespace App\Services;

use App\Models\GpsDevice;
use App\Models\GpsReport;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class CacheService
{
    private const CACHE_TTL = 1440; // 24 hours
    private const SHORT_CACHE_TTL = 60; // 1 hour

    public function __construct(
        private GpsDevice $device
    ) {}

    // ===== REAL-TIME STATE MANAGEMENT METHODS =====

    /**
     * Retrieve the previous report for the current device from the cache.
     *
     * @return array|null The previous report data as an array, or null if not found.
     */
    public function getPreviousReport(): ?array
    {
        $cacheKey = "previous_report_{$this->device->id}";
        return Cache::get($cacheKey);
    }

    /**
     * Store the given report as the previous report for the current device in the cache.
     *
     * @param array $report The report data to cache.
     * @return void
     */
    public function setPreviousReport(array $report): void
    {
        $cacheKey = "previous_report_{$this->device->id}";
        Cache::put($cacheKey, $report, now()->endOfDay());
    }

    /**
     * Retrieve the latest stored GPS report for the current device from the cache.
     *
     * @return GpsReport|null The latest stored GpsReport instance, or null if not found.
     */
    public function getLatestStoredReport(): ?GpsReport
    {
        $cacheKey = "latest_stored_report_{$this->device->id}";
        return Cache::get($cacheKey);
    }

    /**
     * Store the given GPS report as the latest stored report for the current device in the cache.
     *
     * @param GpsReport $report The GpsReport instance to cache.
     * @return void
     */
    public function setLatestStoredReport(GpsReport $report): void
    {
        $cacheKey = "latest_stored_report_{$this->device->id}";
        Cache::put($cacheKey, $report, now()->endOfDay());
    }

    /**
     * Get the validated state for the current device.
     *
     * @return string The validated state ('moving', 'stopped', 'unknown')
     */
    public function getValidatedState(): string
    {
        $cacheKey = "validated_state_{$this->device->id}";
        return Cache::get($cacheKey, 'unknown');
    }

    /**
     * Set the validated state for the current device.
     *
     * @param string $state The state to set ('moving', 'stopped', 'unknown')
     * @return void
     */
    public function setValidatedState(string $state): void
    {
        $cacheKey = "validated_state_{$this->device->id}";
        Cache::put($cacheKey, $state, now()->endOfDay());
    }

    /**
     * Get the pending reports for validation.
     *
     * @return array Array of pending reports
     */
    public function getPendingReports(): array
    {
        $cacheKey = "pending_reports_{$this->device->id}";
        return Cache::get($cacheKey, []);
    }

    /**
     * Add a report to the pending reports queue.
     *
     * @param array $report The report to add to pending queue
     * @return void
     */
    public function addPendingReport(array $report): void
    {
        $cacheKey = "pending_reports_{$this->device->id}";
        $pendingReports = $this->getPendingReports();
        $pendingReports[] = $report;
        Cache::put($cacheKey, $pendingReports, now()->endOfDay());
    }

    /**
     * Clear all pending reports.
     *
     * @return void
     */
    public function clearPendingReports(): void
    {
        $cacheKey = "pending_reports_{$this->device->id}";
        Cache::forget($cacheKey);
    }

    /**
     * Get the consecutive count for state validation.
     *
     * @return int The consecutive count
     */
    public function getConsecutiveCount(): int
    {
        $cacheKey = "consecutive_count_{$this->device->id}";
        return Cache::get($cacheKey, 0);
    }

    /**
     * Set the consecutive count for state validation.
     *
     * @param int $count The consecutive count
     * @return void
     */
    public function setConsecutiveCount(int $count): void
    {
        $cacheKey = "consecutive_count_{$this->device->id}";
        Cache::put($cacheKey, $count, now()->endOfDay());
    }

    /**
     * Increment the consecutive count.
     *
     * @return int The new consecutive count
     */
    public function incrementConsecutiveCount(): int
    {
        $count = $this->getConsecutiveCount() + 1;
        $this->setConsecutiveCount($count);
        return $count;
    }

    /**
     * Reset the consecutive count to zero.
     *
     * @return void
     */
    public function resetConsecutiveCount(): void
    {
        $this->setConsecutiveCount(0);
    }

    // ===== HISTORICAL DATA CACHING METHODS =====

    /**
     * Get daily metrics for a specific date with caching.
     *
     * @param string $date Date in Y-m-d format
     * @return array|null Daily metrics data or null if not found
     */
    public function getDailyMetrics(string $date): ?array
    {
        $cacheKey = "daily_metrics_{$this->device->id}_{$date}";

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL), function () use ($date) {
            return $this->loadDailyMetrics($date);
        });
    }

    /**
     * Get working points for a specific date with caching.
     *
     * @param string $date Date in Y-m-d format
     * @return array Working points data
     */
    public function getWorkingPoints(string $date): array
    {
        $cacheKey = "working_points_{$this->device->id}_{$date}";

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL), function () use ($date) {
            return $this->loadWorkingPoints($date);
        });
    }

    /**
     * Get recent reports within a time range with caching.
     *
     * @param Carbon $from Start time
     * @param Carbon $to End time
     * @return array Recent reports data
     */
    public function getRecentReports(Carbon $from, Carbon $to): array
    {
        $cacheKey = "recent_reports_{$this->device->id}_{$from->format('Y-m-d-H-i')}_{$to->format('Y-m-d-H-i')}";

        return Cache::remember($cacheKey, now()->addMinutes(self::SHORT_CACHE_TTL), function () use ($from, $to) {
            return $this->loadRecentReports($from, $to);
        });
    }

    /**
     * Warm cache for frequently accessed data.
     *
     * @param string $date Date in Y-m-d format
     * @return void
     */
    public function warmCache(string $date): void
    {
        // Pre-load frequently accessed data
        $this->getDailyMetrics($date);
        $this->getWorkingPoints($date);
    }

    /**
     * Invalidate cache for a specific date.
     *
     * @param string $date Date in Y-m-d format
     * @return void
     */
    public function invalidateCache(string $date): void
    {
        $patterns = [
            "daily_metrics_{$this->device->id}_{$date}",
            "working_points_{$this->device->id}_{$date}",
            "recent_reports_{$this->device->id}_*"
        ];

        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }

    // ===== PRIVATE HELPER METHODS =====

    /**
     * Load daily metrics from database.
     *
     * @param string $date Date in Y-m-d format
     * @return array|null Daily metrics data or null if not found
     */
    private function loadDailyMetrics(string $date): ?array
    {
        $dailyReport = $this->device->tractor->gpsMetricsCalculations()
            ->where('date', $date)
            ->first();

        return $dailyReport ? $dailyReport->toArray() : null;
    }

    /**
     * Load working points from database.
     *
     * @param string $date Date in Y-m-d format
     * @return array Working points data
     */
    private function loadWorkingPoints(string $date): array
    {
        return $this->device->reports()
            ->whereDate('date_time', $date)
            ->where(function ($query) {
                $query->where('is_starting_point', true)
                      ->orWhere('is_ending_point', true);
            })
            ->select(['id', 'date_time', 'coordinate', 'is_starting_point', 'is_ending_point'])
            ->get()
            ->toArray();
    }

    /**
     * Load recent reports from database.
     *
     * @param Carbon $from Start time
     * @param Carbon $to End time
     * @return array Recent reports data
     */
    private function loadRecentReports(Carbon $from, Carbon $to): array
    {
        return $this->device->reports()
            ->whereBetween('date_time', [$from, $to])
            ->orderBy('date_time')
            ->select(['id', 'date_time', 'speed', 'status', 'coordinate'])
            ->get()
            ->toArray();
    }
}
