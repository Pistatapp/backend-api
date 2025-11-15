<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkerPathResource extends JsonResource
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
            'coordinate' => $this->coordinate,
            'speed' => $this->speed,
            'bearing' => $this->bearing,
            'accuracy' => $this->accuracy,
            'provider' => $this->provider,
            'date_time' => $this->date_time,
        ];
    }
}
