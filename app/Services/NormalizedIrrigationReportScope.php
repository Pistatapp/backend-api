<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * The resolved, farm-scoped selection used by irrigation reporting.
 *
 * The selected fields/plots/valves retain the request's hierarchy while
 * relevantValveIds is the final valve universe intersected with each
 * irrigation program during volume calculation.
 */
final class NormalizedIrrigationReportScope
{
    public function __construct(
        public readonly Collection $fields,
        public readonly Collection $plots,
        public readonly Collection $valves,
        public readonly array $relevantValveIds,
        public readonly float $physicalAreaM2,
        public readonly string $areaSource,
    ) {}

    public function physicalAreaHa(): float
    {
        return $this->physicalAreaM2 / 10000;
    }

    public function metadata(): array
    {
        return [
            'physical_area_m2' => $this->physicalAreaM2,
            'physical_area_ha' => $this->physicalAreaHa(),
            'area_source' => $this->areaSource,
        ];
    }
}
