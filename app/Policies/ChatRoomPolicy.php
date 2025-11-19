<?php

namespace App\Policies;

use App\Models\ChatRoom;
use App\Models\User;

class ChatRoomPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Users can view their own chat rooms
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ChatRoom $chatRoom): bool
    {
        // User must be a member of the farm and the chat room
        $isFarmMember = $chatRoom->farm->users->contains($user);
        $isChatRoomMember = $chatRoom->users()
            ->wherePivotNull('left_at')
            ->where('users.id', $user->id)
            ->exists();

        return $isFarmMember && $isChatRoomMember;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true; // Users can create chat rooms in their farms
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ChatRoom $chatRoom): bool
    {
        // Only creator or farm admin can update
        $isCreator = $chatRoom->created_by === $user->id;
        $isFarmAdmin = $chatRoom->farm->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();

        return $isCreator || $isFarmAdmin;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ChatRoom $chatRoom): bool
    {
        // Only creator or farm admin can delete
        $isCreator = $chatRoom->created_by === $user->id;
        $isFarmAdmin = $chatRoom->farm->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();

        return $isCreator || $isFarmAdmin;
    }

    /**
     * Determine whether the user can send messages in the chat room.
     */
    public function send(User $user, ChatRoom $chatRoom): bool
    {
        // User must be an active member of the chat room and farm
        $isFarmMember = $chatRoom->farm->users->contains($user);
        $isActiveMember = $chatRoom->users()
            ->wherePivotNull('left_at')
            ->where('users.id', $user->id)
            ->exists();

        return $isFarmMember && $isActiveMember;
    }

    /**
     * Determine whether the user can add users to the chat room.
     */
    public function addUsers(User $user, ChatRoom $chatRoom): bool
    {
        // Only creator or farm admin can add users
        $isCreator = $chatRoom->created_by === $user->id;
        $isFarmAdmin = $chatRoom->farm->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();

        return $isCreator || $isFarmAdmin;
    }

    /**
     * Determine whether the user can remove users from the chat room.
     */
    public function removeUsers(User $user, ChatRoom $chatRoom): bool
    {
        // Only creator or farm admin can remove users
        $isCreator = $chatRoom->created_by === $user->id;
        $isFarmAdmin = $chatRoom->farm->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();

        return $isCreator || $isFarmAdmin;
    }
}

