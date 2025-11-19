<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketMessageResource extends JsonResource
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
            'message' => $this->message,
            'is_support_reply' => $this->is_support_reply,
            'sender_name' => $this->when(
                $this->relationLoaded('user') && $this->user,
                $this->user->username ?? $this->user->mobile
            ),
            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'updated_at' => jdate($this->updated_at)->format('Y/m/d H:i:s'),
        ];
    }
}

