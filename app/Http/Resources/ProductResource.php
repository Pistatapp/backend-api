<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'can_delete' => $this->farms()->doesntExist(),
            'product_types' => ProductTypeResource::collection($this->whenLoaded('productTypes')),
        ];
    }
}
