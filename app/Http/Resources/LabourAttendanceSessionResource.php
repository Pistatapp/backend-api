<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabourAttendanceSessionResource extends JsonResource
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
            'date' => $this->when($this->date, fn() => $this->date->toDateString()),
            'entry_time' => $this->when($this->entry_time, fn() => $this->entry_time->toIso8601String()),
            'exit_time' => $this->when($this->exit_time, fn() => $this->exit_time->toIso8601String()),
            'total_in_zone_duration' => $this->total_in_zone_duration ?: 0,
            'total_out_zone_duration' => $this->total_out_zone_duration ?: 0,
            'status' => $this->status,
        ];
    }
}

