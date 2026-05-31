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
            'treatable_type' => $this->getTreatableType($this->treatable_type),
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

    /**
     * Get the treatable type in a readable format.
     *
     * @return string
     */
    private function getTreatableType(string $treatableType): string
    {
        return match ($treatableType) {
            'App\Models\Field' => 'field',
            'App\Models\Row' => 'row',
            'App\Models\Tree' => 'tree',
            'App\Models\Plot' => 'plot',
            default => 'unknown',
        };
    }
}
