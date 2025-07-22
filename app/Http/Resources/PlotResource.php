<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlotResource extends JsonResource
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
            'name' => $this->name,
            'coordinates' => $this->coordinates,
            'field_id' => $this->field_id,
            'created_at' => jdate($this->created_at)->format('Y/m/d'),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
