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
            'mobile_verified_at' => $this->mobile_verified_at,
            'last_activity_at' => $this->last_activity_at,
            'photo' => $this->getFirstMediaUrl('photo'),
            'token' => $this->createToken('mobile')->plainTextToken,
            'new_user' => $this->wasChanged('mobile_verified_at'),
            'profile' => new ProfileResource($this->whenLoaded('profile')),
        ];
    }
}
