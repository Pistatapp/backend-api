<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkerDeviceResource extends JsonResource
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
            'device_type' => $this->device_type,
            'name' => $this->name,
            'imei' => $this->imei,
            'sim_number' => $this->sim_number,
            'device_fingerprint' => $this->device_fingerprint,
            'created_at' => jdate($this->created_at)->format('Y-m-d H:i:s'),
        ];
    }
}

