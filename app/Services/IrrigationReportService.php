<?php

namespace App\Services;

use App\Models\Irrigation;
use App\Models\Valve;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class IrrigationReportService
{
    /**
     * Get aggregated daily reports (irrigations + accumulated) using arbitrary filters.
     *
     * @param array $plotIds
     * @param array $filters Must include 'from_date' and 'to_date' (Carbon instances)
     * @return array
     */
    public function getAggregatedReports(array $plotIds, array $filters): array
    {
        $irrigations = $this->getFilteredIrrigations($plotIds, $filters);

        // Generate daily reports (already in cubic meters)
        $dailyReports = $this->generateDailyReports($irrigations, $filters['from_date'], $filters['to_date']);

        // Calculate accumulated values from daily reports
        $accumulated = $this->calculateAccumulatedValues($dailyReports);

        return [
            'irrigations' => $dailyReports,
            'accumulated' => $accumulated,
        ];
    }

    /**
     * Get irrigation reports for a plot within a date range
     *
     * @param array $plotIds
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    public function getDateRangeReports(array $plotIds, Carbon $fromDate, Carbon $toDate): array
    {
        $irrigations = $this->getFilteredIrrigations($plotIds, [
            'from_date' => $fromDate,
            'to_date' => $toDate
        ]);

        $dailyReports = $this->generateDailyReports($irrigations, $fromDate, $toDate);
        $accumulated = $this->calculateAccumulatedValues($dailyReports);

        return [
            'irrigations' => $dailyReports,
            'accumulated' => $accumulated
        ];
    }

    /**
     * Get irrigation reports for a plot with specific valves
     *
     * @param array $plotIds
     * @param array $valveIds
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    public function getValveSpecificReports(array $plotIds, array $valveIds, Carbon $fromDate, Carbon $toDate): array
    {
        $irrigations = $this->getFilteredIrrigations($plotIds, [
            'valves' => $valveIds,
            'from_date' => $fromDate,
            'to_date' => $toDate
        ]);

        $dailyReports = $this->generateValveSpecificDailyReports($irrigations, $valveIds, $fromDate, $toDate);
        $accumulated = $this->calculateAccumulatedValuesFromValveReports($dailyReports);

        return [
            'irrigations' => $dailyReports,
            'accumulated' => $accumulated
        ];
    }

    /**
     * Get irrigation reports for a plot with specific labour
     *
     * @param array $plotIds
     * @param int $labourId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    public function getLabourSpecificReports(array $plotIds, int $labourId, Carbon $fromDate, Carbon $toDate): array
    {
        $irrigations = $this->getFilteredIrrigations($plotIds, [
            'labour_id' => $labourId,
            'from_date' => $fromDate,
            'to_date' => $toDate
        ]);

        $dailyReports = $this->generateDailyReports($irrigations, $fromDate, $toDate);
        $accumulated = $this->calculateAccumulatedValues($dailyReports);

        return [
            'irrigations' => $dailyReports,
            'accumulated' => $accumulated
        ];
    }

    /**
     * Get filtered irrigations query
     *
     * @param array $plotIds
     * @param array $filters
     * @return Collection
     */
    private function getFilteredIrrigations(array $plotIds, array $filters): Collection
    {
        $query = Irrigation::whereHas('plots', function ($query) use ($plotIds) {
                $query->whereIn('plots.id', $plotIds);
            })
            ->filter('finished')
            ->verifiedByAdmin()
            ->when($filters['labour_id'] ?? null, function ($query) use ($filters) {
                $query->where('labour_id', $filters['labour_id']);
            })->when($filters['valves'] ?? null, function ($query) use ($filters) {
                $query->whereHas('valves', function ($query) use ($filters) {
                    $query->whereIn('valves.id', $filters['valves']);
                });
            })
            ->whereBetween('start_time', [
                $filters['from_date']->startOfDay(),
                $filters['to_date']->endOfDay(),
            ])
            ->with([
                'valves' => function ($query) use ($filters) {
                    if (isset($filters['valves'])) {
                        $query->whereIn('valves.id', $filters['valves']);
                    }
                },
                'labour'
            ]);

        return $query->get();
    }

    /**
     * Generate daily reports for date range
     *
     * @param Collection $irrigations
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function generateDailyReports(Collection $irrigations, Carbon $fromDate, Carbon $toDate): array
    {
        $dailyReports = [];
        $currentDate = $fromDate->copy();

        while ($currentDate->lte($toDate)) {
            $dailyIrrigations = $irrigations->filter(function ($irrigation) use ($currentDate) {
                $irrigationDate = $irrigation->start_time;

                return $irrigationDate instanceof Carbon && $irrigationDate->isSameDay($currentDate);
            });

            // Skip dates with no irrigations
            if ($dailyIrrigations->isEmpty()) {
                $currentDate->addDay();
                continue;
            }

            $dailyReport = $this->calculateDailyTotals($dailyIrrigations, $currentDate);

            // Skip dates with all zero values
            if (!$this->hasNonZeroValues($dailyReport)) {
                $currentDate->addDay();
                continue;
            }

            $dailyReports[] = $dailyReport;

            $currentDate->addDay();
        }

        return $dailyReports;
    }

    /**
     * Generate valve-specific daily reports
     *
     * @param Collection $irrigations
     * @param array $valveIds
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
        private function generateValveSpecificDailyReports(Collection $irrigations, array $valveIds, Carbon $fromDate, Carbon $toDate): array
    {
        $dailyReports = [];
        $currentDate = $fromDate->copy();

        // Get valve names once for efficiency
        $valveNames = Valve::whereIn('id', $valveIds)->pluck('name', 'id')->toArray();

        while ($currentDate->lte($toDate)) {
            $dailyIrrigations = $irrigations->filter(function ($irrigation) use ($currentDate) {
                $irrigationDate = $irrigation->start_time;

                return $irrigationDate instanceof Carbon && $irrigationDate->isSameDay($currentDate);
            });

            // Skip dates with no irrigations
            if ($dailyIrrigations->isEmpty()) {
                $currentDate->addDay();
                continue;
            }

            $irrigationPerValve = [];
            $hasNonZeroValveData = false;

            foreach ($valveIds as $valveId) {
                $valveIrrigations = $dailyIrrigations->filter(function ($irrigation) use ($valveId) {
                    return $irrigation->valves->contains('id', $valveId);
                });

                $valveKey = $valveNames[$valveId] ?? "valve{$valveId}";
                $valveReport = $this->calculateValveSpecificTotals($valveIrrigations, $valveId);
                $irrigationPerValve[$valveKey] = $valveReport;

                // Check if this valve has non-zero values
                if ($this->hasNonZeroValveValues($valveReport)) {
                    $hasNonZeroValveData = true;
                }
            }

            // Skip dates where all valves have zero values
            if (!$hasNonZeroValveData) {
                $currentDate->addDay();
                continue;
            }

            $dailyReports[] = [
                'date' => jdate($currentDate)->format('Y/m/d'),
                'irrigation_per_valve' => $irrigationPerValve
            ];

            $currentDate->addDay();
        }

        return $dailyReports;
    }

    /**
     * Calculate daily totals for irrigations
     *
     * @param Collection $dailyIrrigations
     * @param Carbon $date
     * @return array
     */
    private function calculateDailyTotals(Collection $dailyIrrigations, Carbon $date): array
    {
        $totalDuration = 0;
        $totalVolume = 0;
        $totalVolumePerHectare = 0;

        foreach ($dailyIrrigations as $irrigation) {
            /** @var \App\Models\Irrigation $irrigation */
            $durationInSeconds = $this->calculateIrrigationDuration($irrigation);
            $totalDuration += $durationInSeconds;

            foreach ($irrigation->valves as $valve) {
                /** @var \App\Models\Valve $valve */
                // Calculate volume in liters, then convert to cubic meters
                $volumeInLiters = ($valve->dripper_count * $valve->dripper_flow_rate) * ($durationInSeconds / 3600);
                $volumeInCubicMeters = $volumeInLiters / 1000;
                $totalVolume += $volumeInCubicMeters;
                $totalVolumePerHectare += ($volumeInCubicMeters / $valve->irrigation_area);
            }
        }

        return [
            'date' => jdate($date)->format('Y/m/d'),
            'total_duration' => to_time_format($totalDuration),
            'total_volume' => $totalVolume,
            'total_volume_per_hectare' => $totalVolumePerHectare,
            'total_count' => $dailyIrrigations->count()
        ];
    }

    /**
     * Calculate valve-specific totals
     *
     * @param Collection $valveIrrigations
     * @param int $valveId
     * @return array
     */
    private function calculateValveSpecificTotals(Collection $valveIrrigations, int $valveId): array
    {
        $totalDuration = 0;
        $totalVolume = 0;
        $totalVolumePerHectare = 0;

        foreach ($valveIrrigations as $irrigation) {
            /** @var \App\Models\Irrigation $irrigation */
            $durationInSeconds = $this->calculateIrrigationDuration($irrigation);
            $totalDuration += $durationInSeconds;

            /** @var \App\Models\Valve|null $valve */
            $valve = $irrigation->valves->firstWhere('id', $valveId);
            if ($valve) {
                // Calculate volume in liters, then convert to cubic meters
                $volumeInLiters = ($valve->dripper_count * $valve->dripper_flow_rate) * ($durationInSeconds / 3600);
                $volumeInCubicMeters = $volumeInLiters / 1000;
                $totalVolume += $volumeInCubicMeters;
                $totalVolumePerHectare += $volumeInCubicMeters / $valve->irrigation_area;
            }
        }

        return [
            'total_duration' => to_time_format($totalDuration),
            'total_volume' => $totalVolume,
            'total_volume_per_hectare' => $totalVolumePerHectare,
            'total_count' => $valveIrrigations->count()
        ];
    }

    /**
     * Calculate accumulated values from daily reports
     *
     * @param array $dailyReports
     * @return array
     */
    private function calculateAccumulatedValues(array $dailyReports): array
    {
        $totalDurationSeconds = 0;
        $totalVolume = 0;
        $totalVolumePerHectare = 0;
        $totalCount = 0;

        foreach ($dailyReports as $report) {
            // Convert time format back to seconds for accumulation
            $totalDurationSeconds += $this->timeFormatToSeconds($report['total_duration']);
            $totalVolume += $report['total_volume'];
            $totalVolumePerHectare += $report['total_volume_per_hectare'];
            $totalCount += $report['total_count'];
        }

        return [
            'total_duration' => to_time_format($totalDurationSeconds),
            'total_volume' => $totalVolume,
            'total_volume_per_hectare' => $totalVolumePerHectare,
            'total_count' => $totalCount
        ];
    }

    /**
     * Calculate accumulated values from valve-specific reports
     *
     * @param array $dailyReports
     * @return array
     */
    private function calculateAccumulatedValuesFromValveReports(array $dailyReports): array
    {
        $totalDurationSeconds = 0;
        $totalVolume = 0;
        $totalVolumePerHectare = 0;
        $totalCount = 0;

        foreach ($dailyReports as $report) {
            foreach ($report['irrigation_per_valve'] as $valveReport) {
                $totalDurationSeconds += $this->timeFormatToSeconds($valveReport['total_duration']);
                $totalVolume += $valveReport['total_volume'];
                $totalVolumePerHectare += $valveReport['total_volume_per_hectare'];
                $totalCount += $valveReport['total_count'];
            }
        }

        return [
            'total_duration' => to_time_format($totalDurationSeconds),
            'total_volume' => $totalVolume,
            'total_volume_per_hectare' => $totalVolumePerHectare,
            'total_count' => $totalCount
        ];
    }

    /**
     * Calculate irrigation duration from start_time to end_time
     *
     * @param \App\Models\Irrigation $irrigation
     * @return int Duration in seconds
     */
    private function calculateIrrigationDuration(\App\Models\Irrigation $irrigation): int
    {
        return $irrigation->start_time->diffInSeconds($irrigation->end_time);
    }

    /**
     * Convert time format (HH:MM:SS) to seconds
     *
     * @param string $timeFormat
     * @return int
     */
    private function timeFormatToSeconds(string $timeFormat): int
    {
        $parts = explode(':', $timeFormat);
        return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
    }

    /**
     * Check if a daily report has non-zero values
     *
     * @param array $report
     * @return bool
     */
    private function hasNonZeroValues(array $report): bool
    {
        return $report['total_count'] > 0
            || $report['total_volume'] > 0
            || $report['total_volume_per_hectare'] > 0
            || $this->timeFormatToSeconds($report['total_duration']) > 0;
    }

    /**
     * Check if a valve-specific report has non-zero values
     *
     * @param array $valveReport
     * @return bool
     */
    private function hasNonZeroValveValues(array $valveReport): bool
    {
        return $valveReport['total_count'] > 0
            || $valveReport['total_volume'] > 0
            || $valveReport['total_volume_per_hectare'] > 0
            || $this->timeFormatToSeconds($valveReport['total_duration']) > 0;
    }
}
