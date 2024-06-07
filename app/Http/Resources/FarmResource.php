<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmResource extends JsonResource
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
            'user_id' => $this->user_id,
            'name' => $this->name,
            'coordinates' => $this->coordinates,
            'center' => $this->center,
            'zoom' => $this->zoom,
            'area' => number_format($this->area, 2),
            'product' => $this->whenLoaded('product', function () {
                return [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                ];
            }),
            'fields_count' => $this->whenCounted('fields'),
            'trees_count' => $this->whenCounted('trees'),
            'labors_count' => $this->whenCounted('labors'),
            'trucktors_count' => $this->whenCounted('trucktors'),
            'plans_count' => $this->whenCounted('plans'),
            'is_working_environment' => $this->is_working_environment,
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
