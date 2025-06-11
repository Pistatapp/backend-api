<?php

namespace App\Services;

use App\Models\GpsDevice;
use App\Models\GpsReport;
use Illuminate\Support\Facades\Cache;

class CacheService
{
    public function __construct(
        private GpsDevice $device
    ) {}

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
}
