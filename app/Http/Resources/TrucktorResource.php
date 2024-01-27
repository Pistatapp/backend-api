<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrucktorResource extends JsonResource
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
            'driver' => $this->whenLoaded('driver'),
            'gps_device' => $this->whenLoaded('gpsDevice'),
            'created_at' => jdate($this->created_at)->format('Y-m-d H:i:s'),
            'can' => [
                'add_driver' => $this->driver()->doesntExist(),
                'add_gps_device' => $this->gpsDevice()->doesntExist(),
            ],
        ];
    }
}
