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
            'name' => $this->profile->name,
            'mobile' => $this->mobile,
            'username' => $this->username,
            'last_activity_at' => jdate($this->last_activity_at)->format('Y/m/d H:i:s'),
            'role' => $this->resolveRoleForEnvironment($request),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ]
        ];
    }

    /**
     * Resolve the role for the user in the working environment.
     *
     * @param Request $request
     * @return string
     */
    private function resolveRoleForEnvironment(Request $request): string
    {
        $workingEnvironmentId = $request->input('_working_environment_id');
        if ($workingEnvironmentId) {
            return $this->farms->firstWhere('id', $workingEnvironmentId)->pivot->role;
        }
        return $this->getRoleNames()->first();
    }
}
