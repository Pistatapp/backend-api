<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabourResource extends JsonResource
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
            'teams' => TeamResource::collection($this->whenLoaded('teams')),
            'fname' => $this->fname,
            'lname' => $this->lname,
            'full_name' => $this->full_name,
            'national_id' => $this->national_id,
            'mobile' => $this->mobile,
            'work_type' => $this->work_type,
            'work_days' => $this->whenNotNull($this->work_days),
            'work_hours' => $this->whenNotNull($this->work_hours),
            'start_work_time' => $this->whenNotNull($this->start_work_time),
            'end_work_time' => $this->whenNotNull($this->end_work_time),
            'monthly_salary' => $this->whenNotNull($this->monthly_salary),
            'hourly_wage' => $this->whenNotNull($this->hourly_wage),
            'overtime_hourly_wage' => $this->whenNotNull($this->overtime_hourly_wage),
            'user_id' => $this->whenNotNull($this->user_id),
            'is_working' => $this->is_working,
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s')
        ];
    }
}

