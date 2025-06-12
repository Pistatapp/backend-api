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
            'team' => new TeamResource($this->whenLoaded('team')),
            'type' => $this->type,
            'fname' => $this->fname,
            'lname' => $this->lname,
            'national_id' => $this->national_id,
            'mobile' => $this->mobile,
            'position' => $this->position,
            'project_start_date' => $this->whenNotNull($this->project_start_date),
            'project_end_date' => $this->whenNotNull($this->project_end_date),
            'work_type' => $this->work_type,
            'work_days' => $this->whenNotNull($this->work_days),
            'work_hours' => $this->whenNotNull($this->work_hours),
            'start_work_time' => $this->whenNotNull($this->start_work_time),
            'end_work_time' => $this->whenNotNull($this->end_work_time),
            'salary' => $this->whenNotNull($this->salary),
            'daily_salary' => $this->whenNotNull($this->daily_salary),
            'monthly_salary' => $this->whenNotNull($this->monthly_salary),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s')
        ];
    }
}
