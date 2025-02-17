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
            'gps_device' => [
                'id' => $this->gpsDevice->id,
                'imei' => $this->gpsDevice->imei,
            ],
            'driver' => [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
                'mobile' => $this->driver->mobile,
            ],
            'status' => $this->gpsReports->first()->status ?? 0,
        ];
    }
}
