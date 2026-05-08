<?php

namespace App\Services;

use App\Models\MaintenanceReport;
use App\Models\Tractor;
use App\Notifications\MaintenanceRequiredNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MaintenanceDistanceMonitorService
{
    /**
     * Sum daily GPS traveled distance (km) since the reference point of the latest maintenance report.
     */
    public function traveledDistanceKmSinceLastReport(Tractor $tractor, MaintenanceReport $latestReport): float
    {
        $start = $this->distancePeriodStart($latestReport);

        return (float) $tractor->gpsMetricsCalculations()
            ->whereNull('tractor_task_id')
            ->where('date', '>=', $start->toDateString())
            ->sum('traveled_distance');
    }

    /**
     * First calendar day to include in GPS sums after maintenance / repair shop exit.
     */
    public function distancePeriodStart(MaintenanceReport $report): Carbon
    {
        if ($report->repair_shop_exited_at) {
            return $report->repair_shop_exited_at->copy()->addDay()->startOfDay();
        }

        return $report->date->copy()->startOfDay();
    }

    /**
     * Notify farm admins once per report threshold when traveled distance exceeds next_maintenance_km.
     */
    public function checkTractorAndNotify(Tractor $tractor): void
    {
        $latestReport = $tractor->maintenanceReports()
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if (! $latestReport || $latestReport->next_maintenance_km === null) {
            return;
        }

        $threshold = (float) $latestReport->next_maintenance_km;
        if ($threshold <= 0) {
            return;
        }

        $distance = $this->traveledDistanceKmSinceLastReport($tractor, $latestReport);

        if ($distance < $threshold) {
            return;
        }

        $cacheKey = $this->notificationCacheKey($tractor, $latestReport);

        if (Cache::has($cacheKey)) {
            return;
        }

        $farm = $tractor->farm;
        if (! $farm) {
            return;
        }

        $farm->admins->each(function ($admin) use ($tractor, $distance, $threshold, $latestReport) {
            $admin->notify(new MaintenanceRequiredNotification(
                $tractor,
                $distance,
                $threshold,
                $latestReport
            ));
        });

        Cache::forever($cacheKey, true);
    }

    public function notificationCacheKey(Tractor $tractor, MaintenanceReport $report): string
    {
        return sprintf(
            'maintenance_km_due:%s:%d:%d:%s',
            $tractor->getMorphClass(),
            $tractor->getKey(),
            $report->getKey(),
            (string) $report->next_maintenance_km
        );
    }

    /**
     * Check all tractors that belong to farms with at least one maintenance report carrying next_maintenance_km.
     */
    public function checkAllTractors(): void
    {
        $tractorIds = DB::table('maintenance_reports')
            ->select('maintainable_id')
            ->where('maintainable_type', Tractor::class)
            ->whereNotNull('next_maintenance_km')
            ->distinct()
            ->pluck('maintainable_id');

        Tractor::query()
            ->whereIn('id', $tractorIds)
            ->chunkById(100, function ($tractors) {
                foreach ($tractors as $tractor) {
                    $this->checkTractorAndNotify($tractor);
                }
            });
    }
}
