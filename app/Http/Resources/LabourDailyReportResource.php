<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabourDailyReportResource extends JsonResource
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
            'labour' => new LabourResource($this->whenLoaded('labour')),
            'date' => $this->date->toDateString(),
            'scheduled_hours' => $this->scheduled_hours,
            'actual_work_hours' => $this->actual_work_hours,
            'overtime_hours' => $this->overtime_hours,
            'time_outside_zone' => $this->time_outside_zone,
            'productivity_score' => $this->productivity_score,
            'status' => $this->status,
            'admin_added_hours' => $this->admin_added_hours,
            'admin_reduced_hours' => $this->admin_reduced_hours,
            'notes' => $this->notes,
            'approver' => $this->whenLoaded('approver'),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

