<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropResource extends JsonResource
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
            'cold_requirement' => $this->cold_requirement,
            'is_active' => $this->is_active,
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
            'crop_types' => CropTypeResource::collection($this->whenLoaded('cropTypes')),
            'can' => [
                'update' => $request->user()->can('update', $this),
                'delete' => $request->user()->can('delete', $this),
            ]
        ];
    }
}
