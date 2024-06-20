<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ColdRequirementNotificationResource extends JsonResource
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
            'farm_id' => $this->farm_id,
            'start_dt' => $this->start_dt,
            'end_dt' => $this->end_dt,
            'min_temp' => $this->min_temp,
            'max_temp' => $this->max_temp,
            'cold_requirement' => $this->cold_requirement,
            'method' => $this->method,
            'note' => $this->note,
            'notified' => $this->notified,
            'notified_at' => $this->whenNotNull($this->notified_at),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
