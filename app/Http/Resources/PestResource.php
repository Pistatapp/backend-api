<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PestResource extends JsonResource
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
            'scientific_name' => $this->scientific_name,
            'description' => $this->description,
            'damage' => $this->damage,
            'management' => $this->management,
            'image' => $this->getFirstMediaUrl('images'),
            'standard_day_degree' => number_format($this->standard_day_degree, 2),
            'created_at' => jdate($this->created_at)->format('Y-m-d H:i:s'),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
        ];
    }
}
