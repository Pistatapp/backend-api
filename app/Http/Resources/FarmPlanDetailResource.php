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
            'treatable' => $this->getTreatableResource(),
        ];
    }

    /**
     * Get the treatable resource.
     *
     * @return mixed
     */
    private function getTreatableResource()
    {
        return match ($this->treatable_type) {
            'App\Models\Field' => new FieldResource($this->whenLoaded('treatable')),
            'App\Models\Row' => new RowResource($this->whenLoaded('treatable')),
            'App\Models\Tree' => new TreeResource($this->whenLoaded('treatable')),
            'App\Models\Plot' => new PlotResource($this->whenLoaded('treatable')),
            default => null,
        };
    }
}
