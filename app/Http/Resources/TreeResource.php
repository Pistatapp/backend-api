<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TreeResource extends JsonResource
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
            'row_id' => $this->row_id,
            'name' => $this->when($this->name, $this->name),
            'product' => $this->when($this->product, $this->product),
            'location' => $this->location,
            'image' => $this->when($this->image, $this->image),
            'unique_id' => $this->when($this->unique_id, $this->unique_id),
            'qr_code' => $this->when($this->qr_code, $this->qr_code),
        ];
    }
}
