<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketDetailResource extends JsonResource
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
            'title' => $this->title,
            'status' => $this->status,
            'last_reply_by' => $this->last_reply_by,
            'last_reply_at' => $this->last_reply_at ? jdate($this->last_reply_at)->format('Y/m/d H:i:s') : null,
            'closed_at' => $this->closed_at ? jdate($this->closed_at)->format('Y/m/d H:i:s') : null,
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'updated_at' => jdate($this->updated_at)->format('Y/m/d H:i:s'),
            'messages' => TicketMessageResource::collection($this->whenLoaded('messages')),
            'metadata' => $this->when(
                $this->relationLoaded('metadata') && $this->metadata,
                function () {
                    return [
                        'error_message' => $this->metadata->error_message,
                        'error_trace' => $this->metadata->error_trace,
                        'page_path' => $this->metadata->page_path,
                        'app_version' => $this->metadata->app_version,
                        'device_model' => $this->metadata->device_model,
                        'occurred_at' => $this->metadata->occurred_at ? jdate($this->metadata->occurred_at)->format('Y/m/d H:i:s') : null,
                    ];
                }
            ),
        ];
    }
}

