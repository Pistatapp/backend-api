<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NutrientSampleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Formats numeric values with 2 decimal places.
     * Includes field information when the relationship is loaded.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'field' => $this->whenLoaded('field', function() {
                return [
                    'id' => $this->field->id,
                    'name' => $this->field->name,
                ];
            }),
            'field_area' => number_format($this->field_area, 2),
            'load_amount' => number_format($this->load_amount, 2),
            'nitrogen' => number_format($this->nitrogen, 2),
            'phosphorus' => number_format($this->phosphorus, 2),
            'potassium' => number_format($this->potassium, 2),
            'calcium' => number_format($this->calcium, 2),
            'magnesium' => number_format($this->magnesium, 2),
            'iron' => number_format($this->iron, 2),
            'copper' => number_format($this->copper, 2),
            'zinc' => number_format($this->zinc, 2),
            'boron' => number_format($this->boron, 2),
        ];
    }
}
