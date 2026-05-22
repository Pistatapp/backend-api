<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppReleaseResource extends JsonResource
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
            'version' => $this->version,
            'release_notes' => $this->release_notes,
            'published_at' => jdate($this->published_at)->format('Y/m/d'),
            'file_url' => url('storage/'.$this->file_url),
            'created_by' => $this->creator->username,
            'created_at' => jdate($this->created_at)->format('Y/m/d'),
        ];
    }
}
