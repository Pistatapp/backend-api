<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PhonologyGuideFileResource extends JsonResource
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
            'created_by' => $this->user->username,
            'file' => $this->getFirstMediaUrl('phonology_guide_files'),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}
