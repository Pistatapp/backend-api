<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointsResource extends JsonResource
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
            'latitude' => $this->coordinate[0],
            'longitude' => $this->coordinate[1],
            'speed' => $this->speed,
            'status' => $this->status,
            'is_starting_point' => $this->is_starting_point,
            'is_ending_point' => $this->is_ending_point,
            'is_stopped' => $this->is_stopped,
            'stoppage_time' => gmdate('H:i:s', $this->stoppage_time),
            'date_time' => jdate($this->date_time)->format('Y/m/d H:i:s'),
        ];
    }
}
