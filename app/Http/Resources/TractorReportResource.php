<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TractorReportResource extends JsonResource
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
            'date' => jdate($this->date)->format('Y/m/d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'operation' => $this->operation->name,
            'field' => $this->field->name,
            'description' => $this->description,
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
