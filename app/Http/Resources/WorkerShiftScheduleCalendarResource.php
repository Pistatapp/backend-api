<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class WorkerShiftScheduleCalendarResource extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // This resource handles grouped calendar data
        // The collection is grouped by scheduled_date
        return $this->collection->map(function ($schedules, $date) {
            return [
                'date' => $date,
                'schedules' => WorkerShiftScheduleResource::collection($schedules),
            ];
        })->values()->all();
    }
}
