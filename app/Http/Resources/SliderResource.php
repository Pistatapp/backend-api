<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SliderResource extends JsonResource
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
            'page' => $this->page,
            'is_active' => $this->is_active,
            'interval' => $this->interval,
            'images' => collect($this->images)->map(function ($image) {
                return [
                    'sort_order' => $image['sort_order'],
                    'url' => asset('storage/' . $image['path']),
                ];
            })->sortBy('sort_order')->values(),
        ];
    }
}
