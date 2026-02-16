<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabourResource extends JsonResource
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
            'personnel_number' => $this->whenNotNull($this->personnel_number),
            'mobile' => $this->mobile,
            'image' => $this->when($this->image, fn () => asset('storage/' . $this->image)),
            'teams' => TeamResource::collection($this->whenLoaded('teams')),
            'created_at' => jdate($this->created_at)->format('Y/m/d'),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ]
        ];
    }
}

