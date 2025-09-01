<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TractorTaskResource extends JsonResource
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
            'operation' => $this->whenLoaded('operation', function () {
                return [
                    'id' => $this->operation->id,
                    'name' => $this->operation->name,
                ];
            }),
            'taskable' => $this->whenLoaded('taskable', function () {
                return [
                    'id' => $this->taskable->id,
                    'name' => $this->taskable->name,
                    'coordinates' => $this->taskable->coordinates,
                ];
            }),
            'date' => jdate($this->date)->format('Y/m/d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'status' => $this->status,
            $this->mergeWhen($this->data, [
                'consumed_water' => data_get($this->data, 'consumed_water'),
                'consumed_fertilizer' => data_get($this->data, 'consumed_fertilizer'),
                'consumed_poison' => data_get($this->data, 'consumed_poison'),
                'operation_area' => data_get($this->data, 'operation_area'),
                'workers_count' => data_get($this->data, 'workers_count'),
            ]),
            'created_by' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ];
            }),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
