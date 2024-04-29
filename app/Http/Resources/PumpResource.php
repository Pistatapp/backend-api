<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PumpResource extends JsonResource
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
            'name' => $this->name,
            'serial_number' => $this->serial_number,
            'model' => $this->model,
            'manufacturer' => $this->manufacturer,
            'horsepower' => $this->horsepower,
            'phase' => $this->phase,
            'voltage' => $this->voltage,
            'ampere' => $this->ampere,
            'rpm' => $this->rpm,
            'pipe_size' => $this->pipe_size,
            'debi' => $this->debi,
            'location' => $this->location,
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
