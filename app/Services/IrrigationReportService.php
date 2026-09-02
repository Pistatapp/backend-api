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
 * that overlaps that local calendar day. Daily and period m³/ha use
 * irrigated hectare-occurrences from selected Plot/Kart irrigation areas
 * (unique per program), never the sum of daily m³/ha values.
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
     * Daily intensity:
     *   Daily volume / Daily irrigated hectare-occurrences
     *
     * Each overlapping irrigation program contributes its unique selected
     * Plot/Kart irrigation areas once for that day (not multiplied by hours).
     *
     * @return array<string, mixed>
     */
    private function calculateDailyTotals(
        Collection $irrigations,
        NormalizedIrrigationReportScope $scope,
        Carbon $dayStart,
        Carbon $dayEnd,
    ): array {
        $dailyIntervals = [];
        $totalVolumeLiters = 0.0;
        $irrigatedAreaHa = 0.0;
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

            $dailyIntervals[] = [
                'start' => max($irrigation->start_time->getTimestamp(), $dayStart->getTimestamp()),
                'end' => min($irrigation->end_time->getTimestamp(), $dayEnd->getTimestamp()),
            ];
            $totalVolumeLiters += $this->calculator->volumeLiters(
                $irrigation->valves,
                $durationInSeconds,
            );
            // Area participates once per daily irrigation occurrence.
            $irrigatedAreaHa += $this->calculator->irrigatedAreaHectares($irrigation->valves);
            $totalCount++;
        }

        $totalVolumeM3 = $totalVolumeLiters / 1000;
        $totalDurationSeconds = $this->calculator->unionDurationSeconds($dailyIntervals);

        return [
            'date' => jdate($dayStart)->format('Y/m/d'),
            'total_duration' => to_time_format($totalDurationSeconds),
            'total_volume' => $totalVolumeM3,
            'irrigated_area_ha' => $irrigatedAreaHa,
            // Compatibility alias for older clients/tests.
            'total_irrigation_area' => $irrigatedAreaHa,
            'total_volume_per_hectare' => $this->calculator->volumePerHectareFromHa(
                $totalVolumeM3,
                $irrigatedAreaHa,
            ),
            'total_count' => $totalCount,
            // Retained metadata; not used as the m³/ha denominator.
            'physical_area_m2' => $scope->physicalAreaM2,
            'physical_area_ha' => $scope->physicalAreaHa(),
            'area_source' => 'irrigation_area_ha',
        ];
    }

    /**
     * Period/footer intensity (must NOT sum daily m³/ha):
     *   Period total volume / Period sum of irrigated hectare-occurrences
     *
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
        $totalIrrigatedAreaHa = 0.0;

        foreach ($dailyReports as $report) {
            $totalDurationSeconds += $this->timeFormatToSeconds($report['total_duration']);
            $totalVolumeM3 += (float) $report['total_volume'];
            $totalIrrigatedAreaHa += (float) ($report['irrigated_area_ha']
                ?? $report['total_irrigation_area']
                ?? 0);
        }

        return [
            'total_duration' => to_time_format($totalDurationSeconds),
            'total_volume' => $totalVolumeM3,
            'total_irrigated_area_ha' => $totalIrrigatedAreaHa,
            // Compatibility alias.
            'total_irrigation_area' => $totalIrrigatedAreaHa,
            'total_volume_per_hectare' => $this->calculator->volumePerHectareFromHa(
                $totalVolumeM3,
                $totalIrrigatedAreaHa,
            ),
            // Count each irrigation program once for the period, even when
            // its interval crosses multiple daily rows.
            'total_count' => $irrigations->count(),
            // Retained metadata; not used as the m³/ha denominator.
            'physical_area_m2' => $scope->physicalAreaM2,
            'physical_area_ha' => $scope->physicalAreaHa(),
            'area_source' => 'irrigation_area_ha',
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
