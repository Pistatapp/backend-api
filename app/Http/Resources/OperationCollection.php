<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class OperationCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->collection->map(function ($operation) {
            return [
                'id' => $operation->id,
                'name' => $operation->name,
                'created_at' => jdate($operation->created_at)->format('Y/m/d H:i:s'),
            ];
        })->toArray();
    }
}
