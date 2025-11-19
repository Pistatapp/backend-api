<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class TicketAttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Generate signed URL for file access (valid for 1 hour)
        $signedUrl = URL::temporarySignedRoute(
            'support.attachments.download',
            now()->addHour(),
            ['attachment' => $this->id]
        );

        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'file_size_human' => $this->resource->getHumanReadableSize(),
            'mime_type' => $this->mime_type,
            'download_url' => $signedUrl,
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
        ];
    }
}

