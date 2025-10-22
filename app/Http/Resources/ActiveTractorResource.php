<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActiveTractorResource extends JsonResource
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
            'status' => $this->is_working,
            'start_working_time' => $this->calculated_start_work_time ?? $this->start_work_time ?? '08:00:00',
            'end_working_time' => $this->calculated_end_work_time ?? $this->end_work_time ?? '17:00:00',
            'on_time' => $this->on_time,
        ];
    }
}
