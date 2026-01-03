<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileDeviceStatusResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource['status'] ?? 'unknown',
            'message' => $this->resource['message'] ?? '',
            'device_id' => $this->resource['device_id'] ?? null,
            'request_id' => $this->resource['request_id'] ?? null,
        ];
    }
}

