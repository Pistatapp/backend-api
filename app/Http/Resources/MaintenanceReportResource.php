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
            'maintenance' => [
                'id' => $this->maintenance->id,
                'name' => $this->maintenance->name,
            ],
            'maintainable' => [
                'id' => $this->maintainable->id,
                'name' => $this->maintainable->name,
            ],
            'maintained_by' => [
                'id' => $this->maintainedBy->id,
                'name' => $this->maintainedBy->full_name,
            ],
            'date' => jdate($this->date)->format('Y/m/d'),
            'description' => $this->description,
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
