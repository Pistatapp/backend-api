<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabourStatusResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'labour' => [
                'id' => $this->id,
                'name' => $this->full_name,
            ],
            'latest_gps' => $this->latest_gps,
            'attendance_session' => new LabourAttendanceSessionResource($this->whenLoaded('activeAttendanceSession')),
        ];
    }
}

