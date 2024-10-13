<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VolkOilSprayResource extends JsonResource
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
            'start_dt' => jdate($this->start_dt)->format('Y/m/d'),
            'end_dt' => jdate($this->end_dt)->format('Y/m/d'),
            'min_temp' => $this->min_temp,
            'max_temp' => $this->max_temp,
            'cold_requirement' => $this->cold_requirement,
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
