<?php

namespace App\Http\Resources;

use App\Models\Field;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TractorTaskResource extends JsonResource
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
            'operation' => [
                'id' => $this->operation->id,
                'name' => $this->operation->name,
            ],
            'fields' => Field::whereIn('id', $this->field_ids)->get()->map(function ($field) {
                return [
                    'id' => $field->id,
                    'name' => $field->name,
                ];
            }),
            'name' => $this->name,
            'start_date' => jdate($this->start_date)->format('Y/m/d'),
            'end_date' => jdate($this->end_date)->format('Y/m/d'),
            'status' => $this->status,
            'description' => $this->description,
            'created_by' => [
                'id' => $this->creator->id,
                'name' => $this->creator->username,
            ],
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
