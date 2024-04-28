<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ValveResource extends JsonResource
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
            'pump_id' => $this->pump_id,
            'name' => $this->name,
            'location' => $this->location,
            'flow_rate' => $this->flow_rate,
            'is_open' => $this->is_open,
        ];
    }
}
