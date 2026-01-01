<?php

namespace App\Services;

use App\Models\Irrigation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PumpIrrigationReportService
{
    /**
     * Get pump irrigation reports for a date range
     *
     * @param int $pumpId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    public function getPumpReports(int $pumpId, Carbon $fromDate, Carbon $toDate): array
    {
        $irrigations = $this->getFilteredIrrigations($pumpId, $fromDate, $toDate);

        $dailyReports = $this->generateDailyReports($irrigations, $fromDate, $toDate);
        $accumulated = $this->calculateAccumulatedValues($dailyReports);

        return [
            'irrigations' => $dailyReports,
            'accumulated' => $accumulated,
        ];
    }

    /**
     * Get filtered irrigations for pump
     *
     * @param int $pumpId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return Collection
     */
    private function getFilteredIrrigations(int $pumpId, Carbon $fromDate, Carbon $toDate): Collection
    {
        return Irrigation::where('pump_id', $pumpId)
            ->filter('finished')
            ->verifiedByAdmin()
            ->whereBetween('start_time', [
                $fromDate->copy()->startOfDay(),
                $toDate->copy()->endOfDay(),
            ])
            ->with('valves')
            ->get();
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
     * Calculate daily totals for irrigations
     *
     * @param Collection $dailyIrrigations
     * @param Carbon $date
     * @return array
     */
    private function calculateDailyTotals(Collection $dailyIrrigations, Carbon $date): array
    {
        $totalDurationSeconds = 0;
        $totalVolume = 0; // in liters

        foreach ($dailyIrrigations as $irrigation) {
            /** @var \App\Models\Irrigation $irrigation */
            $durationInSeconds = $this->calculateIrrigationDuration($irrigation);
            $totalDurationSeconds += $durationInSeconds;

            foreach ($irrigation->valves as $valve) {
                /** @var \App\Models\Valve $valve */
                // Calculate volume in liters
                $volumeInLiters = ($valve->dripper_count * $valve->dripper_flow_rate) * ($durationInSeconds / 3600);
                $totalVolume += $volumeInLiters;
            }
        }

        // Convert volume from liters to cubic meters
        $totalVolumeCubicMeters = $totalVolume / 1000;
        // Calculate hours from seconds
        $totalHours = $totalDurationSeconds / 3600;

        return [
            'date' => jdate($date)->format('Y/m/d'),
            'hours' => round($totalHours, 2),
            'volume' => round($totalVolumeCubicMeters, 2),
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
        $accumulatedHours = 0;
        $accumulatedVolume = 0;

        foreach ($dailyReports as $report) {
            $accumulatedHours += $report['hours'];
            $accumulatedVolume += $report['volume'];
        }

        return [
            'hours' => round($accumulatedHours, 2),
            'volume' => round($accumulatedVolume, 2),
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
     * Check if a daily report has non-zero values
     *
     * @param array $report
     * @return bool
     */
    private function hasNonZeroValues(array $report): bool
    {
        return $report['hours'] > 0 || $report['volume'] > 0;
    }
}

