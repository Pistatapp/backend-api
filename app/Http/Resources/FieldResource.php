<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FieldResource extends JsonResource
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
            'coordinates' => $this->coordinates,
            'center' => $this->center,
            'area' => $this->area,
            'product_type' => $this->whenLoaded('productType', function () {
                return [
                    'id' => $this->productType->id,
                    'name' => $this->productType->name,
                ];
            }),
            'rows_count' => $this->whenCounted('rows'),
            'blocks_count' => $this->whenCounted('blocks'),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
