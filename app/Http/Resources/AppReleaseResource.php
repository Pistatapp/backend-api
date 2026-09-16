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
            'download_url' => $this->id
                ? route('app-releases.download', ['appRelease' => $this->id])
                : null,
            'file' => $media ? [
                'name' => $media->file_name,
                'size' => (int) ($this->file_size ?: $media->size),
                'mime_type' => $this->mime_type ?: 'application/vnd.android.package-archive',
                'sha256' => $this->sha256 ?: $media->getCustomProperty('sha256'),
            ] : null,
            'package_name' => $this->package_name ?: config('app-release.package_name'),
            'version_code' => $this->version_code,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
