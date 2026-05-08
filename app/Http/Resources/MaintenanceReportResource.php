<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceReportResource extends JsonResource
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
            'maintenance' => $this->whenLoaded('maintenance', function () {
                return [
                    'id' => $this->maintenance->id,
                    'name' => $this->maintenance->name,
                ];
            }),
            'maintainable' => $this->whenLoaded('maintainable', function () {
                return [
                    'id' => $this->maintainable->id,
                    'name' => $this->maintainable->name,
                ];
            }),
            'maintained_by' => $this->whenLoaded('maintainedBy', function () {
                return [
                    'id' => $this->maintainedBy->id,
                    'name' => $this->maintainedBy->name,
                ];
            }),
            'date' => jdate($this->date)->format('Y/m/d'),
            'description' => $this->description,
            'repair_shop_entered_at' => $this->repair_shop_entered_at
                ? jdate($this->repair_shop_entered_at)->format('Y/m/d H:i:s')
                : null,
            'repair_shop_exited_at' => $this->repair_shop_exited_at
                ? jdate($this->repair_shop_exited_at)->format('Y/m/d H:i:s')
                : null,
            'repair_duration_hours' => $this->repair_shop_entered_at && $this->repair_shop_exited_at
                ? round($this->repair_shop_entered_at->diffInSeconds($this->repair_shop_exited_at) / 3600, 2)
                : null,
            'next_maintenance_km' => $this->next_maintenance_km !== null
                ? (float) $this->next_maintenance_km
                : null,
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
