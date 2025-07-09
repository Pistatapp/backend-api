<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FieldResource extends JsonResource
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
            'farm_id' => $this->farm_id,
            'name' => $this->name,
            'coordinates' => $this->coordinates,
            'center' => $this->center,
            'area' => $this->area,
            'crop_type' => new CropTypeResource($this->whenLoaded('cropType')),
            'valves' => ValveResource::collection($this->whenLoaded('valves')),
            'rows_count' => $this->whenCounted('rows'),
            'plots_count' => $this->whenCounted('plots'),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'reports' => FarmReportResource::collection($this->whenLoaded('reports')),
            'irrigations' => IrrigationResource::collection($this->whenLoaded('irrigations')),
        ];
    }
}
