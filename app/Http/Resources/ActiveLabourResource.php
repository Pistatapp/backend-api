<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActiveLabourResource extends JsonResource
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
            'coordinate' => $this->coordinate,
            'last_update' => $this->when($this->last_update, function () {
                return $this->last_update instanceof \Carbon\Carbon 
                    ? $this->last_update->toIso8601String() 
                    : $this->last_update;
            }),
            'is_in_zone' => $this->is_in_zone ?: false,
        ];
    }
}

