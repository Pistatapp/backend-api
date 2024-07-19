<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmReportResource extends JsonResource
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
            'date' => jdate($this->date)->format('Y/m/d'),
            'operation' => $this->whenLoaded('operation', function () {
                return [
                    'id' => $this->operation->id,
                    'name' => $this->operation->name,
                ];
            }),
            'labour' => $this->whenLoaded('labour', function () {
                return [
                    'id' => $this->labour->id,
                    'name' => $this->labour->full_name,
                ];
            }),
            'description' => $this->description,
            'value' => $this->value,
            'reportable' => $this->whenLoaded('reportable', function () {
                return [
                    'id' => $this->reportable->id,
                    'name' => $this->reportable->name,
                ];
            }),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
