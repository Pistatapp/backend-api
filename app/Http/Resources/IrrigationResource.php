<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IrrigationResource extends JsonResource
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
            'labor' => [
                'id' => $this->labor->id,
                'name' => $this->labor->fname . ' ' . $this->labor->lname,
            ],
            'date' => jdate($this->date)->format('Y/m/d'),
            'start_time' => $this->start_time->format('H:i'),
            'end_time' => $this->end_time->format('H:i'),
            'valves' => $this->valves()->map(function ($valve) {
                return [
                    'id' => $valve->id,
                    'name' => $valve->name,
                ];
            }),
            'field' => [
                'id' => $this->field->id,
                'name' => $this->field->name,
            ],
            'created_by' => [
                'id' => $this->creator->id,
                'name' => $this->creator->username,
            ],
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s')
        ];
    }
}
