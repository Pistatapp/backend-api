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
            'mobile_number' => $this->mobile_number,
            'imei' => $this->imei,
            'is_active' => $this->is_active,
            'labour' => $this->whenLoaded('labour', function () {
                return [
                    'id' => $this->labour->id,
                    'name' => $this->labour->name,
                    'mobile' => $this->labour->mobile,
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

