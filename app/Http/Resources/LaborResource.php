<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LaborResource extends JsonResource
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
            'project_start_date' => $this->when($this->project_start_date, $this->project_start_date),
            'project_end_date' => $this->when($this->project_end_date, $this->project_end_date),
            'work_type' => $this->work_type,
            'work_days' => $this->when($this->work_days, $this->work_days),
            'work_hours' => $this->when($this->work_hours, $this->work_hours),
            'start_work_time' => $this->when($this->start_work_time, $this->start_work_time),
            'end_work_time' => $this->when($this->end_work_time, $this->end_work_time),
            'salary' => $this->when($this->salary, $this->salary),
            'daily_salary' => $this->when($this->daily_salary, $this->daily_salary),
            'monthly_salary' => $this->when($this->monthly_salary, $this->monthly_salary),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s')
        ];
    }
}
