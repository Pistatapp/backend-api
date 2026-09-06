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
            'taskables' => $this->whenLoaded('taskableItems', function () {
                return $this->taskableItems->map(function ($item) {
                    $model = $item->taskable;
                    if (! $model) {
                        return null;
                    }

                    return [
                        'id' => $model->id,
                        'name' => $model->name ?? $model->title,
                        'coordinates' => $model->coordinates,
                    ];
                })->filter()->values();
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
            'task_execution_duration' => $this->whenLoaded('gpsMetricsCalculation', function () {
                if (! $this->gpsMetricsCalculation) {
                    return null;
                }

                $timings = is_array($this->gpsMetricsCalculation->timings)
                    ? $this->gpsMetricsCalculation->timings
                    : [];
                $seconds = array_key_exists('in_zone_duration_seconds', $timings)
                    ? (int) $timings['in_zone_duration_seconds']
                    : (int) $this->gpsMetricsCalculation->work_duration
                        + (int) $this->gpsMetricsCalculation->stoppage_duration;

                return to_time_format(max(0, $seconds));
            }),
            'task_efficiency' => $this->whenLoaded('gpsMetricsCalculation', function () {
                if (! $this->gpsMetricsCalculation) {
                    return null;
                }

                $seconds = app(\App\Services\TractorEfficiencyService::class)
                    ->taskPresenceDurationSeconds($this->gpsMetricsCalculation);

                return number_format(
                    app(\App\Services\TractorEfficiencyService::class)->calculate($this->tractor, $seconds),
                    2
                );
            }),
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
        $taskStartDateTime = $this->getStartDateTime();
        $taskEndDateTime = $this->getEndDateTime();

        return $now->gte($taskStartDateTime) && $now->lt($taskEndDateTime);
    }
}
