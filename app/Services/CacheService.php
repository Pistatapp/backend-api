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

    public function getPreviousReport(): ?array
    {
        $cacheKey = "previous_report_{$this->device->id}";
        return Cache::get($cacheKey);
    }

    public function setPreviousReport(array $report): void
    {
        $cacheKey = "previous_report_{$this->device->id}";
        Cache::put($cacheKey, $report, now()->endOfDay());
    }

    public function getLatestStoredReport(): ?GpsReport
    {
        $cacheKey = "latest_stored_report_id_{$this->device->id}";
        $latestStoredReportId = Cache::get($cacheKey);
        return GpsReport::find($latestStoredReportId);
    }

    public function setLatestStoredReport(GpsReport $report): void
    {
        $cacheKey = "latest_stored_report_id_{$this->device->id}";
        Cache::put($cacheKey, $report->id, now()->endOfDay());
    }
}
