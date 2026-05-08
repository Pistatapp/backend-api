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
        $media = $this->packageMedia();

        return [
            'id' => $this->id,
            'version' => $this->version,
            'release_notes' => $this->release_notes,
            'published_at' => $this->published_at?->toIso8601String(),
            'download_url' => route('app-releases.download', ['appRelease' => $this->id]),
            'file' => [
                'name' => $media?->file_name,
                'size' => $media?->size,
                'mime_type' => $media?->mime_type,
            ],
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
