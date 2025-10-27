<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Morilog\Jalali\Jalalian;

class FilteredFarmPlanResource extends JsonResource
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
            'from_date' => jdate($this->start_date)->format('Y/m/d'),
            'to_date' => jdate($this->end_date)->format('Y/m/d'),
            'name' => $this->name,
            'treatables' => $this->details->map(function ($detail) {
                return [
                    'name' => $detail->treatable->name ?? 'Unknown',
                    'type' => $this->getTreatableType($detail->treatable_type),
                ];
            })->unique('name')->values(),
        ];
    }

    /**
     * Get the treatable type in a readable format
     */
    private function getTreatableType(string $treatableType): string
    {
        return match ($treatableType) {
            'App\Models\Field' => 'field',
            'App\Models\Row' => 'row',
            'App\Models\Tree' => 'tree',
            default => 'unknown',
        };
    }
}
