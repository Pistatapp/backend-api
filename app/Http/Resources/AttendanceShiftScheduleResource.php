<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceShiftScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new UserResource($this->whenLoaded('user')),
            'shift' => new WorkShiftResource($this->whenLoaded('shift')),
            'scheduled_date' => jdate($this->scheduled_date)->format('Y/m/d'),
            'status' => $this->status,
            'created_at' => jdate($this->created_at)->format('Y/m/d'),
        ];
    }
}
