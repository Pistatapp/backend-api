<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
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
            'tractor_id' => $this->tractor_id,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'employee_code' => $this->employee_code,
            'created_at' => jdate($this->created_at)->format('Y-m-d H:i:s'),
            'can' => [
                'delete' => $this->tractor()->doesntExist(),
            ],
        ];
    }
}
