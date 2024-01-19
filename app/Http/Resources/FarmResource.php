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
            'area' => $this->area,
            'products' => $this->products,
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
