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
            'plots' => $this->whenLoaded('plots', function () {
                return $this->plots->map(function ($plot) {
                    return [
                        'id' => $plot->id,
                        'name' => $plot->name,
                    ];
                });
            }),
            'created_by' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->username,
                ];
            }),
            'note' => $this->note,
            'status' => $this->status,
            'duration' => $this->when($this->status === 'completed', function () {
                return $this->duration;
            }),
            'can' => [
                'delete' => $request->user()->can('delete', $this->resource),
                'update' => $request->user()->can('update', $this->resource),
            ],
        ];
    }
}
