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
 * irrigated hectare-occurrences from the participating valves' configured
 * irrigation_area values, never the sum of daily m³/ha values.
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
        $accumulated = $this->calculateAccumulatedValues(
            $irrigations,
            $dailyReports,
            $scope,
            $rangeStart,
            $rangeEnd,
        );

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
            // Select every program whose interval intersects the requested
            // range. Daily clipping below decides the exact contribution;
            // filtering by start_time would make results depend on range size
            // and would drop valid tails crossing the range start.
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

            $dailyReport = $this->calculateDailyTotals(
                $irrigations,
                $scope,
                $dayStart,
                $dayEnd,
                $rangeStart,
                $rangeEnd,
            );
            if ($dailyReport['total_count'] > 0) {
                $dailyReports[] = $dailyReport;
            }

            $currentDate = $dayEnd;
        }

        return $dailyReports;
    }

    /**
     * Daily intensity:
     *   Daily volume / unique participating valve irrigation areas
     *
     * A valve is counted once for the day even when it appears in multiple
     * programs or relational rows. The area set is built only from programs
     * with a positive clipped interval on this day.
     *
     * @return array<string, mixed>
     */
    private function calculateDailyTotals(
        Collection $irrigations,
        NormalizedIrrigationReportScope $scope,
        Carbon $dayStart,
        Carbon $dayEnd,
        Carbon $rangeStart,
        Carbon $rangeEnd,
    ): array {
        $dailyIntervals = [];
        $totalVolumeLiters = 0.0;
        $dailyValves = [];
        $totalCount = 0;

        foreach ($irrigations as $irrigation) {
            $clippedInterval = $this->calculator->clipIntervalToDayAndRange(
                $irrigation->start_time,
                $irrigation->end_time,
                $dayStart,
                $dayEnd,
                $rangeStart,
                $rangeEnd,
            );

            if ($clippedInterval === null) {
                continue;
            }

            $durationInSeconds = $clippedInterval['seconds'];

            if ($durationInSeconds <= 0) {
                continue;
            }

            $dailyIntervals[] = [
                'start' => $clippedInterval['start'],
                'end' => $clippedInterval['end'],
            ];
            $totalVolumeLiters += $this->calculator->volumeLiters(
                $irrigation->valves,
                $durationInSeconds,
            );
            foreach ($irrigation->valves as $valve) {
                $valveId = (int) ($valve->id ?? 0);
                $key = $valveId > 0 ? (string) $valveId : 'object:'.spl_object_id($valve);
                $dailyValves[$key] = $valve;
            }
            $totalCount++;
        }

        $totalVolumeM3 = $totalVolumeLiters / 1000;
        $totalDurationSeconds = $this->calculator->unionDurationSeconds($dailyIntervals);
        $areaDiagnostics = $this->calculator->selectedValveAreaHectaresWithDiagnostics($dailyValves);
        $hasInvalidArea = $areaDiagnostics['invalid_valve_ids'] !== [];
        $irrigatedAreaHa = $hasInvalidArea ? null : $areaDiagnostics['area_ha'];

        return [
            'date' => jdate($dayStart)->format('Y/m/d'),
            'total_duration' => to_time_format($totalDurationSeconds),
            'total_volume' => $totalVolumeM3,
            'irrigated_area_ha' => $irrigatedAreaHa,
            // Compatibility alias for older clients/tests.
            'total_irrigation_area' => $irrigatedAreaHa,
            'total_volume_per_hectare' => $this->calculator->volumePerHectareFromHa(
                $totalVolumeM3,
                $irrigatedAreaHa ?? 0.0,
            ),
            'total_count' => $totalCount,
            // Retained metadata; not used as the m³/ha denominator.
            'physical_area_m2' => $scope->physicalAreaM2,
            'physical_area_ha' => $scope->physicalAreaHa(),
            'area_source' => 'valve.irrigation_area',
            'invalid_irrigation_area_valve_ids' => $areaDiagnostics['invalid_valve_ids'],
        ];
    }

    /**
     * Period/footer intensity (must NOT sum daily m³/ha):
     *   Period total volume / sum of participating unique valve areas
     *
     * @param list<array<string, mixed>> $dailyReports
     * @return array<string, mixed>
     */
    private function calculateAccumulatedValues(
        Collection $irrigations,
        array $dailyReports,
        NormalizedIrrigationReportScope $scope,
        Carbon $rangeStart,
        Carbon $rangeEnd,
    ): array {
        $totalVolumeM3 = 0.0;
        $participatingValves = [];

        // The period denominator is based on valves with an actual positive
        // interval inside the requested range, not merely selected valves.
        foreach ($irrigations as $irrigation) {
            if ($this->calculator->overlapSeconds(
                $irrigation->start_time,
                $irrigation->end_time,
                $rangeStart,
                $rangeEnd,
            ) <= 0) {
                continue;
            }

            foreach ($irrigation->valves as $valve) {
                $valveId = (int) ($valve->id ?? 0);
                $key = $valveId > 0 ? (string) $valveId : 'object:'.spl_object_id($valve);
                $participatingValves[$key] = $valve;
            }
        }

        $areaDiagnostics = $this->calculator->selectedValveAreaHectaresWithDiagnostics($participatingValves);
        $hasInvalidArea = $areaDiagnostics['invalid_valve_ids'] !== [];
        $totalIrrigatedAreaHa = $hasInvalidArea ? null : $areaDiagnostics['area_ha'];

        foreach ($dailyReports as $report) {
            $totalVolumeM3 += (float) $report['total_volume'];
        }

        return [
            // A range has no additive elapsed-time meaning; daily rows carry
            // the union duration and the product contract keeps this cell '-'.
            'total_duration' => '-',
            'total_volume' => $totalVolumeM3,
            'total_irrigated_area_ha' => $totalIrrigatedAreaHa,
            // Compatibility alias.
            'total_irrigation_area' => $totalIrrigatedAreaHa,
            'total_volume_per_hectare' => $this->calculator->volumePerHectareFromHa(
                $totalVolumeM3,
                $totalIrrigatedAreaHa ?? 0.0,
            ),
            // Count each irrigation program once for the period, even when
            // its interval crosses multiple daily rows.
            'total_count' => $irrigations->count(),
            // Retained metadata; not used as the m³/ha denominator.
            'physical_area_m2' => $scope->physicalAreaM2,
            'physical_area_ha' => $scope->physicalAreaHa(),
            'area_source' => 'valve.irrigation_area',
            'invalid_irrigation_area_valve_ids' => $areaDiagnostics['invalid_valve_ids'],
        ];
    }

    private function asCarbon(mixed $date): Carbon
    {
        return $date instanceof Carbon
            ? $date
            : Carbon::parse($date, IrrigationReportCalculationService::TIMEZONE);
    }
}
