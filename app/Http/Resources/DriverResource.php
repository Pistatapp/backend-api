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
            'tractor' => $this->whenLoaded('tractor', function () {
                return [
                    'id' => $this->tractor->id,
                    'name' => $this->tractor->name,
                ];
            }),
            'farm' => $this->whenLoaded('farm', function () {
                return [
                    'id' => $this->farm->id,
                    'name' => $this->farm->name,
                ];
            }),
            'name' => $this->name,
            'mobile' => $this->mobile,
            'employee_code' => $this->employee_code,
            'created_at' => jdate($this->created_at)->format('Y-m-d H:i:s'),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
        ];
    }
}
