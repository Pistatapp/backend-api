<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RowResource extends JsonResource
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
            'field_id' => $this->field_id,
            'coordinates' => $this->coordinates,
            'reports' => FarmReportResource::collection($this->whenLoaded('reports')),
        ];
    }
}
