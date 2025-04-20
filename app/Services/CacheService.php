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
}
