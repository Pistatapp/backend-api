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
        $role = $this->role();

        return [
            'id' => $this->id,
            'username' => $this->username,
            'mobile' => $this->mobile,
            'photo' => $this->profile->media_url,
            'token' => $this->createToken('token')->plainTextToken,
            'new_user' => is_null($this->username),
            'role' => $role,
            'permissions' => $this->getAllPermissions()->pluck('name'),
        ];
    }

    /**
     * Get the role of the user.
     *
     * @return string
     */
    private function role()
    {
        $workingEnvironment = $this->workingEnvironment();

        if ($workingEnvironment) {
            return $workingEnvironment->pivot->role;
        }

        return $this->getRoleNames()->first();
    }
}
