<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmResource extends JsonResource
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
            'name' => $this->name,
            'coordinates' => $this->coordinates,
            'center' => $this->center,
            'zoom' => $this->zoom,
            'area' => calculate_polygon_area($this->coordinates),
            'crop' => new CropResource($this->whenLoaded('crop')),
            'fields_count' => $this->whenCounted('fields'),
            'trees_count' => $this->whenCounted('trees'),
            'labours_count' => $this->whenCounted('labours'),
            'tractors_count' => $this->whenCounted('tractors'),
            'plans_count' => $this->whenCounted('plans'),
            'is_working_environment' => $this->isWorkingEnvironment(),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'users' => $this->whenLoaded('users', function () {
                return $this->users->map(function ($user) {
                    $role = $user->pivot->role;
                    return [
                        'id' => $user->id,
                        'name' => $user->username,
                        'mobile' => $user->mobile,
                        'role' => $role,
                    ];
                });
            }),
        ];
    }
}
