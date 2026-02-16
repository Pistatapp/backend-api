<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AttendanceShiftScheduleCalendarResource extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return $this->collection->map(function ($schedules, $date) {
            return [
                'date' => $date,
                'schedules' => AttendanceShiftScheduleResource::collection($schedules),
            ];
        })->values()->all();
    }
}
