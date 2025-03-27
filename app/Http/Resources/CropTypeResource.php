<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropTypeResource extends JsonResource
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
            'name' => $this->name,
            'standard_day_degree' => $this->standard_day_degree,
            'phonology_guide_files' => PhonologyGuideFileResource::collection($this->whenLoaded('phonologyGuideFiles')),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
        ];
    }
}
