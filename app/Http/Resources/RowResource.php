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
            'name' => $this->name,
            'coordinates' => $this->coordinates,
            'length' => $this->length,
            'reports' => FarmReportResource::collection($this->whenLoaded('reports')),
            'created_at' => jdate($this->created_at)->format('Y-m-d H:i:s'),
        ];
    }
}
