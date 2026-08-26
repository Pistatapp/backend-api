<?php

namespace App\Services;

use App\Models\Irrigation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PumpIrrigationReportService
{
    public function __construct(
        private IrrigationReportCalculationService $calculator,
    ) {}

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
        [$rangeStart, $rangeEnd] = $this->calculator->reportRange($fromDate, $toDate);
        $accumulated = $this->calculateAccumulatedValues($irrigations, $rangeStart, $rangeEnd);

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
        [$rangeStart, $rangeEnd] = $this->calculator->reportRange($fromDate, $toDate);

        return Irrigation::where('pump_id', $pumpId)
            ->filter('finished')
            ->verifiedByAdmin()
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereColumn('end_time', '>', 'start_time')
            ->where('start_time', '<', $rangeEnd)
            ->where('end_time', '>', $rangeStart)
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
        $currentDate = $fromDate->copy()->setTimezone(IrrigationReportCalculationService::TIMEZONE)->startOfDay();
        $lastDate = $toDate->copy()->setTimezone(IrrigationReportCalculationService::TIMEZONE)->startOfDay();

        while ($currentDate->lte($lastDate)) {
            [$dayStart, $dayEnd] = $this->calculator->reportRange($currentDate, $currentDate);
            $dailyIrrigations = $irrigations->filter(fn ($irrigation): bool =>
                $this->calculator->overlapSeconds(
                    $irrigation->start_time,
                    $irrigation->end_time,
                    $dayStart,
                    $dayEnd,
                ) > 0
            );

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
            [$dayStart, $dayEnd] = $this->calculator->reportRange($date, $date);
            $durationInSeconds = $this->calculator->overlapSeconds(
                $irrigation->start_time,
                $irrigation->end_time,
                $dayStart,
                $dayEnd,
            );
            $totalDurationSeconds += $durationInSeconds;

            $totalVolume += $this->calculator->volumeLiters($irrigation->valves, $durationInSeconds);
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
    private function calculateAccumulatedValues(Collection $irrigations, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $accumulatedSeconds = 0;
        $accumulatedLiters = 0.0;

        foreach ($irrigations as $irrigation) {
            $durationInSeconds = $this->calculator->overlapSeconds(
                $irrigation->start_time,
                $irrigation->end_time,
                $rangeStart,
                $rangeEnd,
            );
            $accumulatedSeconds += $durationInSeconds;
            $accumulatedLiters += $this->calculator->volumeLiters(
                $irrigation->valves,
                $durationInSeconds,
            );
        }

        return [
            'hours' => round($accumulatedSeconds / 3600, 2),
            'volume' => round($accumulatedLiters / 1000, 2),
        ];
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
