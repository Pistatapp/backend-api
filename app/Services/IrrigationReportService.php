<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\Irrigation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the farm irrigation report from the canonical calculation layer.
 *
 * A report row represents the portion of each completed irrigation interval
 * that overlaps that local calendar day. The accumulated row is calculated
 * from those unrounded daily portions and uses the scope area exactly once.
 */
class IrrigationReportService
{
    public function __construct(
        private IrrigationReportCalculationService $calculator,
    ) {}

    /**
     * @param array{
     *     field_ids?: array<int, int>,
     *     plot_ids?: array<int, int>,
     *     valve_ids?: array<int, int>,
     *     valves?: array<int, int>,
     *     labour_id?: int|null,
     *     from_date: Carbon,
     *     to_date: Carbon
     * } $scopeInput
     */
    public function getAggregatedReports(Farm $farm, array $scopeInput): array
    {
        [$rangeStart, $rangeEnd] = $this->calculator->reportRange(
            $this->asCarbon($scopeInput['from_date']),
            $this->asCarbon($scopeInput['to_date']),
        );

        $scope = $this->calculator->normalizeScope($farm, $scopeInput);
        $irrigations = $this->getFilteredIrrigations($farm, $scope, $scopeInput, $rangeStart, $rangeEnd);
        $dailyReports = $this->generateDailyReports($irrigations, $scope, $rangeStart, $rangeEnd);
        $accumulated = $this->calculateAccumulatedValues($irrigations, $dailyReports, $scope);

        return [
            'irrigations' => $dailyReports,
            'accumulated' => $accumulated,
        ];
    }

    /**
     * Backward-compatible service entry point for callers that already have
     * an explicit farm and date range.
     */
    public function getDateRangeReports(
        Farm $farm,
        array $scope,
        Carbon $fromDate,
        Carbon $toDate,
    ): array {
        return $this->getAggregatedReports($farm, array_merge($scope, [
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]));
    }

    /**
     * Legacy valve-specific callers now use the same normalized scope and
     * interval semantics as the main report endpoint.
     */
    public function getValveSpecificReports(
        Farm $farm,
        array $scope,
        array $valveIds,
        Carbon $fromDate,
        Carbon $toDate,
    ): array {
        return $this->getAggregatedReports($farm, array_merge($scope, [
            'valve_ids' => $valveIds,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]));
    }

    /**
     * @return Collection<int, Irrigation>
     */
    private function getFilteredIrrigations(
        Farm $farm,
        NormalizedIrrigationReportScope $scope,
        array $scopeInput,
        Carbon $rangeStart,
        Carbon $rangeEnd,
    ): Collection {
        if ($scope->relevantValveIds === []) {
            return collect();
        }

        return Irrigation::query()
            ->where('farm_id', $farm->id)
            ->filter('finished')
            ->verifiedByAdmin()
            ->whereNotNull('end_time')
            ->whereColumn('end_time', '>', 'start_time')
            ->where('start_time', '<', $rangeEnd)
            ->where('end_time', '>', $rangeStart)
            ->when($scopeInput['labour_id'] ?? null, function ($query, $labourId) {
                $query->where('labour_id', $labourId);
            })
            ->whereHas('valves', function ($query) use ($scope) {
                $query->whereIn('valves.id', $scope->relevantValveIds);
            })
            ->with([
                'valves' => function ($query) use ($scope) {
                    $query->whereIn('valves.id', $scope->relevantValveIds);
                },
                'labour',
                'plots',
            ])
            ->distinct()
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateDailyReports(
        Collection $irrigations,
        NormalizedIrrigationReportScope $scope,
        Carbon $rangeStart,
        Carbon $rangeEnd,
    ): array {
        $dailyReports = [];
        $currentDate = $rangeStart->copy();

        while ($currentDate->lt($rangeEnd)) {
            $dayStart = $currentDate->copy();
            $dayEnd = $currentDate->copy()->addDay();

            $dailyReport = $this->calculateDailyTotals($irrigations, $scope, $dayStart, $dayEnd);
            if ($dailyReport['total_count'] > 0) {
                $dailyReports[] = $dailyReport;
            }

            $currentDate = $dayEnd;
        }

        return $dailyReports;
    }

    /**
     * @return array<string, mixed>
     */
    private function calculateDailyTotals(
        Collection $irrigations,
        NormalizedIrrigationReportScope $scope,
        Carbon $dayStart,
        Carbon $dayEnd,
    ): array {
        $totalDurationSeconds = 0;
        $totalVolumeLiters = 0.0;
        $totalCount = 0;

        foreach ($irrigations as $irrigation) {
            $durationInSeconds = $this->calculator->overlapSeconds(
                $irrigation->start_time,
                $irrigation->end_time,
                $dayStart,
                $dayEnd,
            );

            if ($durationInSeconds <= 0) {
                continue;
            }

            $totalDurationSeconds += $durationInSeconds;
            $totalVolumeLiters += $this->calculator->volumeLiters(
                $irrigation->valves,
                $durationInSeconds,
            );
            $totalCount++;
        }

        $totalVolumeM3 = $totalVolumeLiters / 1000;

        return [
            'date' => jdate($dayStart)->format('Y/m/d'),
            'total_duration' => to_time_format($totalDurationSeconds),
            'total_volume' => $totalVolumeM3,
            'physical_area_m2' => $scope->physicalAreaM2,
            'physical_area_ha' => $scope->physicalAreaHa(),
            'area_source' => $scope->areaSource,
            // Retained as a compatibility alias; it is now physical ha,
            // never a sum of valve metadata or repeated event areas.
            'total_irrigation_area' => $scope->physicalAreaHa(),
            'total_volume_per_hectare' => $this->calculator->volumePerHectare(
                $totalVolumeM3,
                $scope->physicalAreaM2,
            ),
            'total_count' => $totalCount,
        ];
    }

    /**
     * @param list<array<string, mixed>> $dailyReports
     * @return array<string, mixed>
     */
    private function calculateAccumulatedValues(
        Collection $irrigations,
        array $dailyReports,
        NormalizedIrrigationReportScope $scope,
    ): array {
        $totalDurationSeconds = 0;
        $totalVolumeM3 = 0.0;

        foreach ($dailyReports as $report) {
            $totalDurationSeconds += $this->timeFormatToSeconds($report['total_duration']);
            $totalVolumeM3 += (float) $report['total_volume'];
        }

        return [
            'total_duration' => to_time_format($totalDurationSeconds),
            'total_volume' => $totalVolumeM3,
            'physical_area_m2' => $scope->physicalAreaM2,
            'physical_area_ha' => $scope->physicalAreaHa(),
            'area_source' => $scope->areaSource,
            'total_irrigation_area' => $scope->physicalAreaHa(),
            'total_volume_per_hectare' => $this->calculator->volumePerHectare(
                $totalVolumeM3,
                $scope->physicalAreaM2,
            ),
            // Count each irrigation program once for the period, even when
            // its interval crosses multiple daily rows.
            'total_count' => $irrigations->count(),
        ];
    }

    private function timeFormatToSeconds(string $timeFormat): int
    {
        $parts = array_map('intval', explode(':', $timeFormat));

        return (($parts[0] ?? 0) * 3600)
            + (($parts[1] ?? 0) * 60)
            + ($parts[2] ?? 0);
    }

    private function asCarbon(mixed $date): Carbon
    {
        return $date instanceof Carbon
            ? $date
            : Carbon::parse($date, IrrigationReportCalculationService::TIMEZONE);
    }
}
