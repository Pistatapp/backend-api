<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmPlanResource extends JsonResource
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
            'goal' => $this->goal,
            'referrer' => $this->referrer,
            'counselors' => $this->counselors,
            'executors' => $this->executors,
            'statistical_counselors' => $this->statistical_counselors,
            'implementation_location' => $this->implementation_location,
            'used_materials' => $this->used_materials,
            'evaluation_criteria' => $this->evaluation_criteria,
            'description' => $this->description,
            'start_date' => jdate($this->start_date)->format('Y/m/d'),
            'end_date' => jdate($this->end_date)->format('Y/m/d'),
            'status' => $this->status,
            'created_by' => $this->whenLoaded('creator', function () {
                return $this->creator->username;
            }),
            'details' => FarmPlanDetailResource::collection($this->whenLoaded('details')),
        ];
    }
}
