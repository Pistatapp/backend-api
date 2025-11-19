<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatRoomListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $lastMessage = $this->lastMessage;
        
        // Optimized: Use pre-calculated unread_count if available (set in ChatRoomService)
        $unreadCount = $this->unread_count ?? 0;
        
        // Fallback calculation if not pre-calculated
        if (!isset($this->unread_count)) {
            $userPivot = $this->users->firstWhere('id', $user->id);
            $lastReadAt = $userPivot?->pivot?->last_read_at;
            
            if ($lastMessage && $lastReadAt) {
                $unreadCount = $this->messages()
                    ->where('created_at', '>', $lastReadAt)
                    ->whereDoesntHave('deletions', function ($q) use ($user) {
                        $q->where('deleted_by_user_id', $user->id)
                            ->where('deletion_type', 'for_me');
                    })
                    ->whereDoesntHave('deletions', function ($q) {
                        $q->where('deletion_type', 'for_everyone');
                    })
                    ->count();
            } elseif ($lastMessage) {
                $unreadCount = 1; // Has messages but never read
            }
        }

        // Get the other user for private chats
        $otherUser = null;
        if ($this->type === 'private') {
            $otherUser = $this->activeUsers
                ->where('id', '!=', $user->id)
                ->first();
        }

        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'type' => $this->type,
            'name' => $this->type === 'group' ? $this->name : ($otherUser ? ($otherUser->profile->first_name ?? $otherUser->username ?? 'User') : null),
            'last_message' => $lastMessage ? [
                'id' => $lastMessage->id,
                'content' => $lastMessage->isFile() ? $lastMessage->file_name : $lastMessage->content,
                'message_type' => $lastMessage->message_type,
                'sender' => new UserResource($lastMessage->user),
                'created_at' => $lastMessage->created_at->toIso8601String(),
            ] : null,
            'unread_count' => $unreadCount,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

