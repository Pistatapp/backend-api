<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserAttendanceStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->id,
                'name' => $this->profile?->name ?? $this->mobile,
            ],
            'latest_gps' => $this->latest_gps,
            'attendance_session' => new AttendanceSessionResource($this->whenLoaded('activeAttendanceSession')),
        ];
    }
}
