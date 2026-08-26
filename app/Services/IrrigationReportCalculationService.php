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
     * A selected field is the reporting ancestor and owns the denominator;
     * descendant selections cannot add area. Without a selected field,
     * selected plots dominate their valves for area; valve-only selections
     * contribute their unique parent plots.
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
            ->whereIn('id', $requestedValveIds)
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
