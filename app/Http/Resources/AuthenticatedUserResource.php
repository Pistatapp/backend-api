<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthenticatedUserResource extends JsonResource
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
            'last_activity_at' => jdate($this->last_activity_at)->format('Y/m/d H:i:s'),
            'photo' => $this->getFirstMediaUrl('photo'),
            'token' => $this->createToken('mobile', expiresAt: now()->addHour())->plainTextToken,
            'new_user' => $this->wasChanged('mobile_verified_at'),
            'is_admin' => $this->is_admin,
        ];
    }
}
