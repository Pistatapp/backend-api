<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isDeletedForUser = $this->isDeletedForUser($user->id);
        $isDeletedForEveryone = $this->isDeletedForEveryone();

        return [
            'id' => $this->id,
            'chat_room_id' => $this->chat_room_id,
            'user_id' => $this->user_id,
            'message_type' => $this->message_type,
            'content' => $isDeletedForUser || $isDeletedForEveryone ? null : $this->content,
            'file_path' => $isDeletedForUser || $isDeletedForEveryone ? null : $this->file_path,
            'file_name' => $isDeletedForUser || $isDeletedForEveryone ? null : $this->file_name,
            'file_size' => $isDeletedForUser || $isDeletedForEveryone ? null : $this->file_size,
            'file_mime_type' => $isDeletedForUser || $isDeletedForEveryone ? null : $this->file_mime_type,
            'file_url' => $this->isFile() && !$isDeletedForUser && !$isDeletedForEveryone
                ? url('/api/chat-files/' . $this->chat_room_id . '/' . urlencode($this->file_name))
                : null,
            'reply_to_message_id' => $this->reply_to_message_id,
            'edited_at' => $this->edited_at?->toIso8601String(),
            'is_edited' => $this->isEdited(),
            'is_deleted_for_me' => $isDeletedForUser,
            'is_deleted_for_everyone' => $isDeletedForEveryone,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'user' => new UserResource($this->whenLoaded('user')),
            'reply_to' => new MessageResource($this->whenLoaded('replyTo')),
            'reads' => MessageReadResource::collection($this->whenLoaded('reads')),
            'read_by_me' => $this->reads()->where('user_id', $user->id)->exists(),
        ];
    }
}

