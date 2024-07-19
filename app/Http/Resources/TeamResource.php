<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
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
            'farm_id' => $this->farm_id,
            'supervisor' => $this->whenLoaded('supervisor', function () {
                return [
                    'id' => $this->supervisor->id,
                    'fname' => $this->supervisor->fname,
                    'lname' => $this->supervisor->lname,
                ];
            }),
            'labours_count' => $this->whenCounted('labours'),
            'labours' => LabourResource::collection($this->whenLoaded('labours')),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s')
        ];
    }
}
