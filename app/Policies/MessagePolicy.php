<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Users can view messages in their chat rooms
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Message $message): bool
    {
        // User must be a member of the chat room
        return $message->chatRoom->users()
            ->wherePivotNull('left_at')
            ->where('users.id', $user->id)
            ->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true; // Users can send messages in their chat rooms
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Message $message): bool
    {
        // Only sender can edit, and only within 15 minutes
        if ($message->user_id !== $user->id) {
            return false;
        }

        $timeLimit = now()->subMinutes(15);
        return $message->created_at->isAfter($timeLimit);
    }

    /**
     * Determine whether the user can delete the model (for themselves).
     */
    public function delete(User $user, Message $message): bool
    {
        // User can always delete for themselves
        return true;
    }

    /**
     * Determine whether the user can delete the message for everyone.
     */
    public function deleteForEveryone(User $user, Message $message): bool
    {
        // Only sender can delete for everyone, and only within 24 hours
        if ($message->user_id !== $user->id) {
            return false;
        }

        $timeLimit = now()->subHours(24);
        return $message->created_at->isAfter($timeLimit);
    }

    /**
     * Determine whether the user can view messages in a chat room.
     */
    public function viewMessages(User $user, Message $message): bool
    {
        // User must be an active member of the chat room
        return $message->chatRoom->users()
            ->wherePivotNull('left_at')
            ->where('users.id', $user->id)
            ->exists();
    }
}

