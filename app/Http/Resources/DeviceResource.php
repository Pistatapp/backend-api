<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
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
            'mobile_number' => $this->mobile_number,
            'is_active' => $this->is_active,
            'approved_at' => $this->approved_at ? jdate($this->approved_at)->format('Y-m-d H:i:s') : null,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'username' => $this->user->username,
                    'mobile' => $this->user->mobile,
                ];
            }),
            'farm' => $this->whenLoaded('farm', function () {
                return [
                    'id' => $this->farm->id,
                    'name' => $this->farm->name,
                ];
            }),
            'tractor' => $this->whenLoaded('tractor', function () {
                return [
                    'id' => $this->tractor->id,
                    'name' => $this->tractor->name,
                ];
            }),
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
            'created_at' => jdate($this->created_at)->format('Y-m-d H:i:s'),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
        ];
    }
}

