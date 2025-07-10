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
            'plot_id' => $this->plot_id,
            'name' => $this->name,
            'location' => $this->location,
            'is_open' => $this->is_open,
            'irrigation_area' => $this->irrigation_area,
            'dripper_count' => $this->dripper_count,
            'dripper_flow_rate' => $this->dripper_flow_rate,
            'plot' => new PlotResource($this->whenLoaded('plot')),
        ];
    }
}
