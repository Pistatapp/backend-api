<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lastMessage = $this->messages()->latest()->first();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'last_message_preview' => $lastMessage ? substr($lastMessage->message, 0, 100) : null,
            'last_reply_at' => $this->last_reply_at ? jdate($this->last_reply_at)->format('Y/m/d H:i:s') : null,
            'unread_count' => $this->when(
                $request->user()->id === $this->user_id,
                function () {
                    // Count messages from support that user hasn't seen
                    // This is a simplified version - you might want to track read status
                    return $this->messages()
                        ->where('is_support_reply', true)
                        ->where('created_at', '>', $this->last_reply_at ?? $this->created_at)
                        ->count();
                }
            ),
            'created_at' => jdate($this->created_at)->format('Y/m/d H:i:s'),
            'updated_at' => jdate($this->updated_at)->format('Y/m/d H:i:s'),
        ];
    }
}

