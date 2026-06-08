<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActiveTractorResource extends JsonResource
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
            'name' => $this->name,
            'gps_device' => $this->whenLoaded('gpsDevice', function () {
                return [
                    'id' => $this->gpsDevice->id,
                    'imei' => $this->gpsDevice->imei,
                ];
            }),
            'driver' => $this->whenLoaded('driver', function () {
                return [
                    'id' => $this->driver->id,
                    'name' => $this->driver->name,
                    'mobile' => $this->driver->mobile,
                ];
            }),
            'status' => $this->is_working,
            'is_in_repair_shop' => $this->is_in_repair_shop,
            'start_working_time' => $this->calculated_start_work_time ?? null,
        ];
    }
}
