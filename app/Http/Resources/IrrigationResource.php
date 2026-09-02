<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IrrigationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'labour' => $this->whenLoaded('labour', function () {
                return [
                    'id' => $this->labour->id,
                    'name' => $this->labour->name,
                ];
            }),
            'start_date' => $this->start_time ? jdate($this->start_time)->format('Y/m/d') : null,
            'end_date' => $this->end_time ? jdate($this->end_time)->format('Y/m/d') : null,
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'pump' => $this->whenLoaded('pump', function () {
                return [
                    'id' => $this->pump->id,
                    'name' => $this->pump->name,
                ];
            }),
            'valves' => $this->whenLoaded('valves', function () {
                return $this->valves->map(function ($valve) {
                    return [
                        'id' => $valve->id,
                        'name' => $valve->name,
                        'status' => $valve->pivot->status,
                        'opened_at' => $valve->pivot->opened_at?->format('H:i'),
                        'closed_at' => $valve->pivot->closed_at?->format('H:i'),
                    ];
                });
            }),
            'plots' => PlotResource::collection($this->whenLoaded('plots')),
            'created_by' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->username,
                ];
            }),
            'note' => $this->note,
            'status' => $this->status,
            'is_verified_by_admin' => (bool) $this->is_verified_by_admin,
            'lifecycle' => app(\App\Services\IrrigationLifecycleService::class)->payload($this->resource),
            'duration' => to_time_format($this->duration),
            'plots_count' => $this->whenCounted('plots'),
            // area_covered is retained for compatibility and is the
            // configured valve irrigation coverage in hectares. GIS values
            // remain explicitly named physical-area metadata.
            'area_covered' => $this->getIrrigatedAreaHectares(),
            'physical_area_m2' => $this->getPhysicalAreaM2(),
            'physical_area_ha' => $this->getPhysicalAreaM2() / 10000,
            'irrigation_area_ha' => $this->getIrrigatedAreaHectares(),
            'area_source' => 'valve.irrigation_area',
            $this->mergeWhen(in_array($this->status, ['in-progress', 'finished']), [
                'total_volume' => $this->getTotalVolume(),
                'irrigation_per_hectare' => $this->getVolumePerHectare(),
            ]),
            'can' => [
                'delete' => $request->user()->can('delete', $this->resource),
                'update' => $request->user()->can('update', $this->resource),
                'verify' => $request->user()->can('verify', $this->resource),
                'operator_confirm' => $request->user()->can('confirmOperator', $this->resource),
                'admin_confirm' => $request->user()->can('verify', $this->resource),
                'operator_edit' => app(\App\Services\IrrigationLifecycleService::class)->canOperatorEdit($this->resource),
                'admin_edit' => app(\App\Services\IrrigationLifecycleService::class)->canAdminEdit($this->resource),
            ],
        ];
    }

    /**
     * Get the area covered by the irrigation.
     *
     * @return float
     */
    private function getPhysicalAreaM2(): float
    {
        $plots = $this->relationLoaded('plots')
            ? $this->plots
            : $this->plots()->get();

        return app(\App\Services\IrrigationReportCalculationService::class)
            ->physicalAreaForPlots($plots);
    }

    /**
     * Get the total volume of the irrigation.
     *
     * @return float
     */
    private function getTotalVolume(): float
    {
        $liters = app(\App\Services\IrrigationReportCalculationService::class)
            ->volumeLiters($this->valves, $this->duration);

        return $liters / 1000;
    }

    private function getVolumePerHectare(): ?float
    {
        return app(\App\Services\IrrigationReportCalculationService::class)
            ->volumePerHectareFromHa($this->getTotalVolume(), $this->getIrrigatedAreaHectares());
    }

    private function getIrrigatedAreaHectares(): float
    {
        $valves = $this->relationLoaded('valves')
            ? $this->valves
            : $this->valves()->get();

        return app(\App\Services\IrrigationReportCalculationService::class)
            ->irrigatedAreaHectares($valves);
    }
}
