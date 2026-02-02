<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkShiftResource extends JsonResource
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
            'farm_id' => $this->farm_id,
            'name' => $this->name,
            'start_time' => $this->start_time->format('H:i'),
            'end_time' => $this->end_time->format('H:i'),
            'work_hours' => $this->work_hours,
            'labours_count' => $this->whenCounted('labours'),
            'labours' => LabourResource::collection($this->whenLoaded('labours')),
            'created_at' => jdate($this->created_at)->format('Y/m/d'),
        ];
    }
}
