<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkerMonthlyPayrollResource extends JsonResource
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
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'month' => $this->month,
            'year' => $this->year,
            'total_work_hours' => $this->total_work_hours,
            'total_required_hours' => $this->total_required_hours,
            'total_overtime_hours' => $this->total_overtime_hours,
            'base_wage_total' => $this->base_wage_total,
            'overtime_wage_total' => $this->overtime_wage_total,
            'additions' => $this->additions,
            'deductions' => $this->deductions,
            'final_total' => $this->final_total,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
