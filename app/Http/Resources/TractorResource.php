<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TractorResource extends JsonResource
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
            'farm_id' => $this->farm_id,
            'name' => $this->name,
            'start_work_time' => $this->start_work_time,
            'end_work_time' => $this->end_work_time,
            'expected_daily_work_time' => $this->expected_daily_work_time,
            'expected_monthly_work_time' => $this->expected_monthly_work_time,
            'expected_yearly_work_time' => $this->expected_yearly_work_time,
            'driver' => new DriverResource($this->whenLoaded('driver')),
            'gps_device' => new GpsDeviceResource($this->whenLoaded('gpsDevice')),
            'one_week_efficiency_chart_data' => $this->whenLoaded('gpsMetricsCalculations', function () {
                return $this->gpsMetricsCalculations->map(function ($report) {
                    return [
                        'date' => jdate($report->date)->format('Y-m-d'),
                        'efficiency' => number_format($report->efficiency, 2),
                    ];
                });
            }),
            'created_at' => jdate($this->created_at)->format('Y/m/d'),
            'can' => [
                'add_driver' => !$this->relationLoaded('driver') || is_null($this->driver),
                'add_gps_device' => !$this->relationLoaded('gpsDevice') || is_null($this->gpsDevice),
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
        ];
    }
}
