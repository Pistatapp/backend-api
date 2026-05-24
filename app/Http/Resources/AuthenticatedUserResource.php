<?php

namespace App\Http\Resources;

use App\Models\Role;
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
            'role' => $role->name,
            'permissions' => $role->permissions()->pluck('name'),
        ];
    }

    /**
     * Get the role of the user.
     *
     * @return \App\Models\Role
     */
    private function role()
    {
        $workingEnvironment = $this->workingEnvironment();

        $role = null;

        if ($workingEnvironment) {
            $role = $workingEnvironment->pivot->role;
        }

        $role = $role
            ? Role::where('name', $role)->with('permissions')->first()
            : $this->getRoleNames()->first();

        return $role;
    }
}
