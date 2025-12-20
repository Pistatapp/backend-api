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
        $role = $this->getRoleNames();

        // If working environment ID is provided and user is not root, get role from that farm
        $workingEnvironmentId = $request->input('_working_environment_id');
        if ($workingEnvironmentId && !$this->hasRole('root')) {
            // Check if farms are already loaded
            if ($this->relationLoaded('farms')) {
                $farm = $this->farms->firstWhere('id', $workingEnvironmentId);
            } else {
                $farm = $this->farms()->where('farms.id', $workingEnvironmentId)->first();
            }

            if ($farm && $farm->pivot && $farm->pivot->role) {
                $role = $farm->pivot->role;
            }
        }

        return [
            'id' => $this->id,
            'username' => $this->username,
            'last_activity_at' => jdate($this->last_activity_at)->format('Y/m/d H:i:s'),
            'profile' => new ProfileResource($this->whenLoaded('profile')),
            'role' => $role,
            'farms' => new FarmResource($this->whenLoaded('farms')),
        ];
    }
}
