<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmPlanDetailResource extends JsonResource
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
            'farm_plan_id' => $this->farm_plan_id,
            'treatment' => new TreatmentResource($this->whenLoaded('treatment')),
            'coordinates' => $this->treatable->coordinates,
        ];
    }
}
