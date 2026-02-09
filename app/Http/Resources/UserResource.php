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
        if ($workingEnvironmentId) {
            // Check if farms are already loaded
            if ($this->relationLoaded('farms')) {
                $farm = $this->farms->firstWhere('id', $workingEnvironmentId);
            } else {
                $farm = $this->farms()->where('farms.id', $workingEnvironmentId)->first();
            }

            if ($farm && $farm->pivot && $farm->pivot->role) {
                $role = $farm->pivot->role;
            }
        } else {
            $role = $this->getRoleNames()->first();
        }

        return [
            'id' => $this->id,
            'name' => $this->profile->name,
            'mobile' => $this->mobile,
            'username' => $this->username,
            'last_activity_at' => jdate($this->last_activity_at)->format('Y/m/d H:i:s'),
            'role' => $role,
            $this->mergeWhen($role === 'labour', [
                'labour' => new LabourResource($this->whenLoaded('labour')),
            ]),
        ];
    }
}
