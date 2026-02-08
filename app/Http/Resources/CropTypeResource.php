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
            'is_active' => $this->is_active,
            'load_estimation_data' => $this->load_estimation_data,
            'phonology_guide_files' => PhonologyGuideFileResource::collection($this->whenLoaded('phonologyGuideFiles')),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'is_global' => $this->isGlobal(),
            'is_owned' => $request->user() && $this->isOwnedBy($request->user()->id),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'username' => $this->creator->username,
                    'mobile' => $this->creator->mobile,
                ];
            }),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
        ];
    }
}
