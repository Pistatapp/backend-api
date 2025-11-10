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
                    'name' => $this->labour->full_name,
                ];
            }),
            'date' => jdate($this->date)->format('Y/m/d'),
            'start_time' => $this->start_time->format('H:i'),
            'end_time' => $this->end_time->format('H:i'),
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
            'duration' => gmdate('H:i:s', $this->duration),
            'plots_count' => $this->whenCounted('plots'),
            'trees_count' => $this->whenCounted('plots.trees'),
            'area_covered' => $this->getAreaCovered(),
            $this->mergeWhen(in_array($this->status, ['in-progress', 'finished']), [
                'total_volume' => $this->getTotalVolume(),
            ]),
            'can' => [
                'delete' => $request->user()->can('delete', $this->resource),
                'update' => $request->user()->can('update', $this->resource),
                'verify' => $request->user()->can('verify', $this->resource),
            ],
        ];
    }

    /**
     * Get the area covered by the irrigation.
     *
     * @return float
     */
    private function getAreaCovered(): float
    {
        return $this->valves->sum(function ($valve) {
            return $valve->irrigation_area;
        });
    }

    /**
     * Get the total volume of the irrigation.
     *
     * @return float
     */
    private function getTotalVolume(): float
    {
        return $this->valves->sum(function ($valve) {
            $area = $valve->dripper_count * $valve->dripper_flow_rate * ($this->duration / 3600);
            return round($area, 2);
        });
    }
}
