<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceConnectionRequestResource extends JsonResource
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
            'mobile_number' => $this->mobile_number,
            'device_fingerprint' => $this->device_fingerprint,
            'device_info' => $this->device_info,
            'status' => $this->status,
            'rejected_reason' => $this->rejected_reason,
            'farm' => $this->whenLoaded('farm', function () {
                return [
                    'id' => $this->farm->id,
                    'name' => $this->farm->name,
                ];
            }),
            'approver' => $this->whenLoaded('approver', function () {
                return [
                    'id' => $this->approver->id,
                    'username' => $this->approver->username,
                ];
            }),
            'approved_at' => $this->approved_at ? jdate($this->approved_at)->format('Y-m-d H:i:s') : null,
            'created_at' => jdate($this->created_at)->format('Y-m-d H:i:s'),
        ];
    }
}

