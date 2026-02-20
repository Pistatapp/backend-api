<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceTrackingResource extends JsonResource
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
            'user_id' => $this->user_id,
            'farm_id' => $this->farm_id,
            'work_type' => $this->work_type,
            'work_days' => $this->work_days,
            'work_hours' => $this->work_hours,
            'start_work_time' => $this->start_work_time,
            'end_work_time' => $this->end_work_time,
            'hourly_wage' => $this->hourly_wage,
            'overtime_hourly_wage' => $this->overtime_hourly_wage,
            'enabled' => $this->enabled,
        ];
    }
}
