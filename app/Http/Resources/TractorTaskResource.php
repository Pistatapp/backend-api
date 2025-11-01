<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class TractorTaskResource extends JsonResource
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
            'operation' => $this->whenLoaded('operation', function () {
                return [
                    'id' => $this->operation->id,
                    'name' => $this->operation->name,
                ];
            }),
            'taskable' => $this->whenLoaded('taskable', function () {
                return [
                    'id' => $this->taskable->id,
                    'name' => $this->taskable->name,
                    'coordinates' => $this->taskable->coordinates,
                ];
            }),
            'driver' => $this->whenLoaded('tractor.driver', function () {
                return [
                    'id' => $this->driver->id,
                    'name' => $this->driver->name,
                ];
            }),
            'date' => jdate($this->date)->format('Y/m/d'),
            'start_time' => $this->start_time->format('H:i:s'),
            'end_time' => $this->end_time->format('H:i:s'),
            'status' => $this->status,
            'is_current' => $this->isCurrent(),
            $this->mergeWhen($this->data, [
                'consumed_water' => data_get($this->data, 'consumed_water'),
                'consumed_fertilizer' => data_get($this->data, 'consumed_fertilizer'),
                'consumed_poison' => data_get($this->data, 'consumed_poison'),
                'operation_area' => data_get($this->data, 'operation_area'),
                'workers_count' => data_get($this->data, 'workers_count'),
            ]),
            'created_by' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ];
            }),
            'created_at' => jdate($this->created_at)->format('Y/m/d'),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
        ];
    }

    /**
     * Check if the current time falls within the task's date and start/end time
     *
     * @return bool
     */
    private function isCurrent(): bool
    {
        $now = Carbon::now();
        $taskDateTime = Carbon::parse($this->date);

        // Get time string from start_time and end_time (they are cast as datetime objects)
        $startTimeString = $this->start_time->format('H:i');
        $endTimeString = $this->end_time->format('H:i');

        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($startTimeString);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($endTimeString);

        // Handle midnight crossing (e.g., 22:00 - 02:00)
        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        return $now->gte($taskStartDateTime) && $now->lt($taskEndDateTime);
    }
}
