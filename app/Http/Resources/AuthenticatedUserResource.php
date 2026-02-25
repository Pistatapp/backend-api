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
            'photo' => $this->profile->media_url,
            'token' => $this->whenNotNull($this->token),
            'new_user' => is_null($this->username),
        ];
    }
}
