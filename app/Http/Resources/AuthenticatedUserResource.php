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
            'photo' => $this->profile->media_url,
            'token' => $this->createToken('mobile', expiresAt: now()->addDay())->plainTextToken,
            'new_user' => is_null($this->username),
        ];
    }
}
