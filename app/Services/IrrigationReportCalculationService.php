<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Plot;
use App\Models\Valve;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Canonical irrigation-report calculation and scope primitives.
 *
 * All report durations use half-open intervals [start, end) and all date
 * boundaries are local to the application's Asia/Tehran timezone.
 */
class IrrigationReportCalculationService
{
    public const TIMEZONE = 'Asia/Tehran';

    /**
     * Resolve a hierarchical request into a farm-scoped reporting scope.
     *
     * A selected field is the reporting ancestor for physical metadata;
     * descendant selections cannot add physical geometry. Irrigation m³/ha
     * is always derived from the selected/contributing valves' configured
     * irrigation_area values, with each Plot/Kart counted once per program.
     */
    public function normalizeScope(Farm $farm, array $input): NormalizedIrrigationReportScope
    {
        $fieldIds = $this->ids($input['field_ids'] ?? []);
        $plotIds = $this->ids($input['plot_ids'] ?? []);
        $requestedValveIds = $this->ids(
            array_key_exists('valve_ids', $input)
                ? ($input['valve_ids'] ?? [])
                : ($input['valves'] ?? [])
        );

        $fields = Field::query()
            ->where('farm_id', $farm->id)
            ->whereIn('id', $fieldIds)
            ->get();

        $plots = Plot::query()
            ->whereIn('id', $plotIds)
            ->whereHas('field', fn ($query) => $query->where('farm_id', $farm->id))
            ->when($fieldIds !== [], fn ($query) => $query->whereIn('field_id', $fieldIds))
            ->with('field')
            ->get();

        $valves = Valve::query()
            ->when(
                $requestedValveIds !== [],
                fn ($query) => $query->whereIn('id', $requestedValveIds)
            )
            ->whereHas('plot.field', fn ($query) => $query->where('farm_id', $farm->id))
            ->when($fieldIds !== [], fn ($query) => $query->whereHas('plot', fn ($query) => $query->whereIn('field_id', $fieldIds)))
            ->when($plotIds !== [], fn ($query) => $query->whereIn('plot_id', $plotIds))
            ->with('plot.field')
            ->get();

        if ($fields->isNotEmpty()) {
            $relevantValveIds = $requestedValveIds !== []
                ? $valves->modelKeys()
                : Valve::query()
                    ->when(
                        $plotIds !== [],
                        fn ($query) => $query->whereIn('plot_id', $plotIds),
                        fn ($query) => $query->whereHas('plot', fn ($query) => $query->whereIn('field_id', $fields->modelKeys())),
                    )
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

            $physicalAreaM2 = $fields->sum(
                fn (Field $field): float => $this->polygonArea($field->coordinates)
            );

            return new NormalizedIrrigationReportScope(
                fields: $fields,
                plots: $plots,
                valves: $valves,
                relevantValveIds: $relevantValveIds,
                physicalAreaM2: (float) $physicalAreaM2,
                areaSource: 'field_polygon',
            );
        }

        if ($requestedValveIds !== []) {
            $relevantValveIds = $valves
                ->filter(fn (Valve $valve): bool =>
                    $plotIds === [] || in_array((int) $valve->plot_id, $plotIds, true)
                )
                ->modelKeys();
        } elseif ($plotIds !== []) {
            $relevantValveIds = Valve::query()
                ->whereIn('plot_id', $plotIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } else {
            $relevantValveIds = [];
        }

        // Add parent plots for valve-only selections, then deduplicate by ID.
        $areaPlots = $plots
            ->concat($valves->map(fn (Valve $valve) => $valve->plot)->filter())
            ->unique('id')
            ->values();

        $physicalAreaM2 = $areaPlots->sum(
            fn (Plot $plot): float => $this->polygonArea($plot->coordinates)
        );

        return new NormalizedIrrigationReportScope(
            fields: $fields,
            plots: $areaPlots,
            valves: $valves,
            relevantValveIds: array_values(array_unique(array_map('intval', $relevantValveIds))),
            physicalAreaM2: (float) $physicalAreaM2,
            areaSource: $areaPlots->isNotEmpty() ? 'plot_polygon' : 'none',
        );
    }

    /**
     * Convert an inclusive local date request into a half-open interval.
     *
     * [from 00:00:00, day after to 00:00:00)
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function reportRange(Carbon $fromDate, Carbon $toDate): array
    {
        $from = $fromDate->copy()->setTimezone(self::TIMEZONE)->startOfDay();
        $toExclusive = $toDate->copy()->setTimezone(self::TIMEZONE)->addDay()->startOfDay();

        return [$from, $toExclusive];
    }

    /**
     * Return the overlap in seconds between two half-open intervals.
     */
    public function overlapSeconds(
        ?Carbon $intervalStart,
        ?Carbon $intervalEnd,
        Carbon $rangeStart,
        Carbon $rangeEnd,
    ): int {
        if ($intervalStart === null || $intervalEnd === null) {
            return 0;
        }

        $start = max($intervalStart->getTimestamp(), $rangeStart->getTimestamp());
        $end = min($intervalEnd->getTimestamp(), $rangeEnd->getTimestamp());

        return max(0, $end - $start);
    }

    public function durationSeconds(?Carbon $start, ?Carbon $end): int
    {
        if ($start === null || $end === null) {
            return 0;
        }

        return max(0, $end->getTimestamp() - $start->getTimestamp());
    }

    /**
     * Calculate the effective daily irrigation duration from the clock times.
     *
     * Irrigation programs are scheduled by start/end clock time. Some legacy
     * rows contain a calendar end date from a later scheduling cycle, which
     * must not turn a one-day run into several hundred hours in the detail
     * metrics. Equal clock times represent a full 24-hour run.
     */
    public function clockDurationSeconds(?Carbon $start, ?Carbon $end): int
    {
        if ($start === null || $end === null) {
            return 0;
        }

        $startSeconds = ((int) $start->format('H') * 3600)
            + ((int) $start->format('i') * 60)
            + (int) $start->format('s');
        $endSeconds = ((int) $end->format('H') * 3600)
            + ((int) $end->format('i') * 60)
            + (int) $end->format('s');

        $duration = $endSeconds - $startSeconds;

        return $duration > 0 ? $duration : $duration + 24 * 3600;
    }

    /**
     * Measure the union of half-open timestamp intervals.
     *
     * Intervals that overlap or touch represent one continuous period for
     * elapsed-time reporting. The intervals are already expected to be
     * clipped to the requested report day/range by the caller.
     *
     * @param iterable<array{start:int, end:int}> $intervals
     */
    public function unionDurationSeconds(iterable $intervals): int
    {
        $normalized = [];

        foreach ($intervals as $interval) {
            $start = (int) ($interval['start'] ?? 0);
            $end = (int) ($interval['end'] ?? 0);

            if ($end > $start) {
                $normalized[] = [$start, $end];
            }
        }

        usort($normalized, fn (array $left, array $right): int =>
            ($left[0] <=> $right[0]) ?: ($left[1] <=> $right[1])
        );

        $totalSeconds = 0;
        $mergedStart = null;
        $mergedEnd = null;

        foreach ($normalized as [$start, $end]) {
            if ($mergedStart === null) {
                $mergedStart = $start;
                $mergedEnd = $end;
                continue;
            }

            if ($start <= $mergedEnd) {
                $mergedEnd = max($mergedEnd, $end);
                continue;
            }

            $totalSeconds += $mergedEnd - $mergedStart;
            $mergedStart = $start;
            $mergedEnd = $end;
        }

        if ($mergedStart !== null) {
            $totalSeconds += $mergedEnd - $mergedStart;
        }

        return $totalSeconds;
    }

    /**
     * Calculate liters from authoritative irrigation duration.
     */
    public function volumeLiters(iterable $valves, int|float $durationInSeconds): float
    {
        $durationInHours = max(0, (float) $durationInSeconds) / 3600;
        $totalVolumeLiters = 0.0;

        foreach ($valves as $valve) {
            $totalVolumeLiters +=
                ((float) ($valve->dripper_count ?? 0))
                * ((float) ($valve->dripper_flow_rate ?? 0))
                * $durationInHours;
        }

        return $totalVolumeLiters;
    }

    public function volumePerHectare(float $volumeM3, float $physicalAreaM2): ?float
    {
        if ($physicalAreaM2 <= 0) {
            return null;
        }

        return $volumeM3 / ($physicalAreaM2 / 10000);
    }

    /**
     * Irrigated area in hectares for one irrigation program.
     *
     * Uses valve.irrigation_area (authoritative kart area in ha) and counts
     * each Plot/Kart once even if multiple valves reference the same plot.
     */
    public function irrigatedAreaHectares(iterable $valves): float
    {
        $seenPlotKeys = [];
        $totalHa = 0.0;

        foreach ($valves as $valve) {
            $plotId = (int) ($valve->plot_id ?? 0);
            $key = $plotId > 0
                ? 'plot:'.$plotId
                : 'valve:'.(string) ($valve->id ?? spl_object_id($valve));

            if (isset($seenPlotKeys[$key])) {
                continue;
            }

            $seenPlotKeys[$key] = true;
            $valveAreaHa = (float) ($valve->irrigation_area ?? 0);
            if ($valveAreaHa > 0) {
                $totalHa += $valveAreaHa;
            }
        }

        return $totalHa;
    }

    /**
     * m³/ha from volume (m³) and irrigated hectare-occurrences (ha).
     */
    public function volumePerHectareFromHa(float $volumeM3, float $irrigatedAreaHa): ?float
    {
        if ($irrigatedAreaHa <= 0) {
            return null;
        }

        return $volumeM3 / $irrigatedAreaHa;
    }

    /**
     * Total-row denominator: each selected valve contributes its configured
     * irrigation area once, regardless of how many performed events used it.
     */
    public function selectedValveAreaHectares(iterable $valves): float
    {
        $seen = [];
        $totalHa = 0.0;

        foreach ($valves as $valve) {
            $id = (int) ($valve->id ?? 0);
            $key = $id > 0 ? 'valve:'.$id : 'object:'.spl_object_id($valve);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $area = (float) ($valve->irrigation_area ?? 0);
            if ($area > 0) {
                $totalHa += $area;
            }
        }

        return $totalHa;
    }

    public function polygonArea(?array $coordinates): float
    {
        return $coordinates ? (float) calculate_polygon_area($coordinates) : 0.0;
    }

    public function physicalAreaForPlots(iterable $plots): float
    {
        return collect($plots)
            ->filter()
            ->unique(fn ($plot) => $plot->id ?? spl_object_id($plot))
            ->sum(fn (Plot $plot): float => $this->polygonArea($plot->coordinates));
    }

    public function physicalAreaForFields(iterable $fields): float
    {
        return collect($fields)
            ->filter()
            ->unique(fn ($field) => $field->id ?? spl_object_id($field))
            ->sum(fn (Field $field): float => $this->polygonArea($field->coordinates));
    }

    /**
     * Count trees physically located inside a Plot/Kart polygon.
     *
     * Rows are attached to fields in the production schema (they do not have
     * a plot_id column), so a relational Plot::trees() query is not valid.
     * Tree locations are the authoritative child geometry and are filtered
     * against the plot polygon without changing any stored telemetry or tree
     * records.
     */
    public function treesInsidePolygon(iterable $trees, ?array $polygon): int
    {
        if ($polygon === null || count($polygon) < 3) {
            return 0;
        }

        return collect($trees)
            ->filter(function ($tree) use ($polygon): bool {
                $location = $tree->location ?? null;

                if (is_string($location)) {
                    $decoded = json_decode($location, true);
                    $location = is_array($decoded) ? $decoded : explode(',', $location);
                }

                if (! is_array($location) || count($location) < 2) {
                    return false;
                }

                $latitude = $location[0] ?? null;
                $longitude = $location[1] ?? null;

                if (! is_numeric($latitude) || ! is_numeric($longitude)) {
                    return false;
                }

                return is_point_in_polygon(
                    [(float) $latitude, (float) $longitude],
                    $polygon,
                );
            })
            ->count();
    }

    /**
     * Count the trees in a Plot using its owning Field's valid tree relation.
     */
    public function treesInsidePlot(Plot $plot): int
    {
        $field = $plot->relationLoaded('field')
            ? $plot->getRelation('field')
            : $plot->field()->first();

        if ($field === null) {
            return 0;
        }

        return $this->treesInsidePolygon($field->trees()->get(), $plot->coordinates);
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $ids): array
    {
        if (! is_array($ids) && ! $ids instanceof Collection) {
            return [];
        }

        return collect($ids)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
