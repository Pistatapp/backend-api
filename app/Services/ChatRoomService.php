<?php

namespace App\Services;

use App\Models\ChatRoom;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChatRoomService
{
    /**
     * Create or retrieve a private chat room between two users in a farm.
     *
     * @param Farm $farm
     * @param User $user1
     * @param User $user2
     * @return ChatRoom
     */
    public function createPrivateChatRoom(Farm $farm, User $user1, User $user2): ChatRoom
    {
        // Optimized: Use join instead of multiple whereHas queries
        $existingRoom = ChatRoom::where('chat_rooms.farm_id', $farm->id)
            ->where('chat_rooms.type', 'private')
            ->join('chat_room_user as cru1', function ($join) use ($user1) {
                $join->on('cru1.chat_room_id', '=', 'chat_rooms.id')
                    ->where('cru1.user_id', '=', $user1->id)
                    ->whereNull('cru1.left_at');
            })
            ->join('chat_room_user as cru2', function ($join) use ($user2) {
                $join->on('cru2.chat_room_id', '=', 'chat_rooms.id')
                    ->where('cru2.user_id', '=', $user2->id)
                    ->whereNull('cru2.left_at');
            })
            ->select('chat_rooms.*')
            ->first();

        if ($existingRoom) {
            return $existingRoom;
        }

        // Create new private chat room
        $chatRoom = ChatRoom::create([
            'farm_id' => $farm->id,
            'type' => 'private',
            'name' => null,
            'created_by' => $user1->id,
        ]);

        // Attach both users to the chat room
        $chatRoom->users()->attach([
            $user1->id => ['joined_at' => now()],
            $user2->id => ['joined_at' => now()],
        ]);

        return $chatRoom;
    }

    /**
     * Create a group chat room for a farm.
     *
     * @param Farm $farm
     * @param string $name
     * @param array $userIds
     * @param User $creator
     * @return ChatRoom
     */
    public function createGroupChatRoom(Farm $farm, string $name, array $userIds, User $creator): ChatRoom
    {
        // Ensure creator is included
        if (!in_array($creator->id, $userIds)) {
            $userIds[] = $creator->id;
        }

        $chatRoom = ChatRoom::create([
            'farm_id' => $farm->id,
            'type' => 'group',
            'name' => $name,
            'created_by' => $creator->id,
        ]);

        // Attach all users to the chat room
        $attachData = [];
        foreach ($userIds as $userId) {
            $attachData[$userId] = ['joined_at' => now()];
        }
        $chatRoom->users()->attach($attachData);

        return $chatRoom;
    }

    /**
     * Get all chat rooms for a user in a specific farm.
     *
     * @param User $user
     * @param Farm $farm
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUserChatRooms(User $user, Farm $farm)
    {
        // Optimized: Use join instead of whereHas for better performance
        // Calculate unread counts in a single query using subquery
        $chatRooms = ChatRoom::where('chat_rooms.farm_id', $farm->id)
            ->join('chat_room_user', function ($join) use ($user) {
                $join->on('chat_room_user.chat_room_id', '=', 'chat_rooms.id')
                    ->where('chat_room_user.user_id', '=', $user->id)
                    ->whereNull('chat_room_user.left_at');
            })
            ->select('chat_rooms.*', 'chat_room_user.last_read_at as user_last_read_at')
            ->selectRaw('(
                SELECT COUNT(*)
                FROM messages
                WHERE messages.chat_room_id = chat_rooms.id
                AND messages.created_at > COALESCE(chat_room_user.last_read_at, "1970-01-01")
                AND messages.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM message_deletions md1
                    WHERE md1.message_id = messages.id
                    AND md1.deleted_by_user_id = ?
                    AND md1.deletion_type = "for_me"
                )
                AND NOT EXISTS (
                    SELECT 1 FROM message_deletions md2
                    WHERE md2.message_id = messages.id
                    AND md2.deletion_type = "for_everyone"
                )
            ) as unread_count', [$user->id])
            ->with([
                'lastMessage.user.profile',
                'activeUsers.profile',
            ])
            ->orderBy('chat_rooms.last_message_at', 'desc')
            ->get();

        return $chatRooms;
    }

    /**
     * Add users to a chat room.
     *
     * @param ChatRoom $room
     * @param array $userIds
     * @return void
     */
    public function addUsersToChatRoom(ChatRoom $room, array $userIds): void
    {
        // Optimized: Check all users at once instead of one by one
        $existingUsers = $room->users()
            ->whereIn('users.id', $userIds)
            ->pluck('users.id')
            ->toArray();

        $attachData = [];
        $rejoinUserIds = [];

        foreach ($userIds as $userId) {
            if (in_array($userId, $existingUsers)) {
                // User exists, check if they left
                $rejoinUserIds[] = $userId;
            } else {
                // New user
                $attachData[$userId] = ['joined_at' => now()];
            }
        }

        // Batch attach new users
        if (!empty($attachData)) {
            $room->users()->attach($attachData);
        }

        // Batch rejoin users who left
        if (!empty($rejoinUserIds)) {
            $room->users()->whereIn('users.id', $rejoinUserIds)
                ->update([
                    'left_at' => null,
                    'joined_at' => now(),
                ]);
        }
    }

    /**
     * Remove a user from a chat room.
     *
     * @param ChatRoom $room
     * @param User $user
     * @return void
     */
    public function removeUserFromChatRoom(ChatRoom $room, User $user): void
    {
        $room->users()->updateExistingPivot($user->id, [
            'left_at' => now(),
        ]);
    }

    /**
     * Mark chat room as read for a user.
     *
     * @param ChatRoom $room
     * @param User $user
     * @return void
     */
    public function markChatRoomAsRead(ChatRoom $room, User $user): void
    {
        $room->users()->updateExistingPivot($user->id, [
            'last_read_at' => now(),
        ]);
    }
}

