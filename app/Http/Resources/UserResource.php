<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'username' => $this->username,
            'mobile' => $this->mobile,
            'mobile_verified_at' => jdate($this->mobile_verified_at)->format('Y/m/d H:i:s'),
            'last_activity_at' => jdate($this->last_activity_at)->format('Y/m/d H:i:s'),
            'gps_devices_count' => $this->whenCounted('gpsDevices'),
            'profile' => new ProfileResource($this->whenLoaded('profile')),
        ];
    }
}
