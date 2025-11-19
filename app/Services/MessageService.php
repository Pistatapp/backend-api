<?php

namespace App\Services;

use App\Events\MessageDeleted as MessageDeletedEvent;
use App\Events\MessageEdited as MessageEditedEvent;
use App\Events\MessageRead as MessageReadEvent;
use App\Events\MessageSent as MessageSentEvent;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\MessageDeletion;
use App\Models\MessageRead;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;

class MessageService
{
    protected FileStorageService $fileStorageService;
    protected AuditLogService $auditLogService;

    public function __construct(
        FileStorageService $fileStorageService,
        AuditLogService $auditLogService
    ) {
        $this->fileStorageService = $fileStorageService;
        $this->auditLogService = $auditLogService;
    }

    /**
     * Send a text message to a chat room.
     *
     * @param ChatRoom $room
     * @param User $sender
     * @param string $content
     * @param int|null $replyToId
     * @return Message
     */
    public function sendTextMessage(ChatRoom $room, User $sender, string $content, ?int $replyToId = null): Message
    {
        $message = Message::create([
            'chat_room_id' => $room->id,
            'user_id' => $sender->id,
            'message_type' => 'text',
            'content' => $content,
            'reply_to_message_id' => $replyToId,
        ]);

        // Update chat room's last message timestamp
        $room->update(['last_message_at' => now()]);

        // Log the action
        $this->auditLogService->logChatAction('message_sent', $message, [
            'chat_room_id' => $room->id,
            'message_type' => 'text',
        ]);

        // Broadcast the event (load minimal data for broadcasting)
        event(new MessageSentEvent($message->load('user.profile')));

        return $message;
    }

    /**
     * Send a file message to a chat room.
     *
     * @param ChatRoom $room
     * @param User $sender
     * @param UploadedFile $file
     * @return Message
     */
    public function sendFileMessage(ChatRoom $room, User $sender, UploadedFile $file): Message
    {
        // Validate and store the file
        $fileData = $this->fileStorageService->storeFile($file, $room);

        $message = Message::create([
            'chat_room_id' => $room->id,
            'user_id' => $sender->id,
            'message_type' => 'file',
            'file_path' => $fileData['path'],
            'file_name' => $fileData['name'],
            'file_size' => $fileData['size'],
            'file_mime_type' => $fileData['mime_type'],
        ]);

        // Update chat room's last message timestamp
        $room->update(['last_message_at' => now()]);

        // Log the action
        $this->auditLogService->logChatAction('file_uploaded', $message, [
            'chat_room_id' => $room->id,
            'file_name' => $fileData['name'],
            'file_size' => $fileData['size'],
        ]);

        // Broadcast the event (load minimal data for broadcasting)
        event(new MessageSentEvent($message->load('user.profile')));

        return $message;
    }

    /**
     * Delete a message (for self or for everyone).
     *
     * @param Message $message
     * @param User $user
     * @param string $deletionType
     * @return void
     */
    public function deleteMessage(Message $message, User $user, string $deletionType): void
    {
        if ($deletionType === 'for_everyone') {
            // Soft delete the message
            $message->delete();

            // Create deletion record
            MessageDeletion::create([
                'message_id' => $message->id,
                'deleted_by_user_id' => $user->id,
                'deletion_type' => 'for_everyone',
            ]);

            // Delete the file if it's a file message
            if ($message->isFile() && $message->file_path) {
                $this->fileStorageService->deleteFile($message->file_path);
            }

            $this->auditLogService->logChatAction('message_deleted', $message, [
                'deletion_type' => 'for_everyone',
            ]);

            // Broadcast deletion event
            event(new MessageDeletedEvent($message, 'for_everyone', $user->id));
        } else {
            // For self only - just create a deletion record
            MessageDeletion::create([
                'message_id' => $message->id,
                'deleted_by_user_id' => $user->id,
                'deletion_type' => 'for_me',
            ]);

            $this->auditLogService->logChatAction('message_deleted', $message, [
                'deletion_type' => 'for_me',
            ]);

            // Broadcast deletion event
            event(new MessageDeletedEvent($message, 'for_me', $user->id));
        }
    }

    /**
     * Edit a message.
     *
     * @param Message $message
     * @param string $newContent
     * @return Message
     */
    public function editMessage(Message $message, string $newContent): Message
    {
        $message->update([
            'content' => $newContent,
            'edited_at' => now(),
        ]);

        $this->auditLogService->logChatAction('message_edited', $message, [
            'original_content_length' => strlen($message->getOriginal('content')),
        ]);

        // Broadcast edit event
        event(new MessageEditedEvent($message->fresh()));

        return $message->fresh();
    }

    /**
     * Mark a message as read by a user.
     *
     * @param Message $message
     * @param User $user
     * @return MessageRead
     */
    public function markMessageAsRead(Message $message, User $user): MessageRead
    {
        $messageRead = MessageRead::firstOrCreate(
            [
                'message_id' => $message->id,
                'user_id' => $user->id,
            ],
            [
                'read_at' => now(),
            ]
        );

        // Broadcast read event if it was just created
        if ($messageRead->wasRecentlyCreated) {
            event(new MessageReadEvent($message, $user));
        }

        return $messageRead;
    }

    /**
     * Get read status for a message.
     *
     * @param Message $message
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMessageReadStatus(Message $message)
    {
        return $message->reads()->with('user')->get();
    }

    /**
     * Get messages for a chat room with pagination.
     *
     * @param ChatRoom $room
     * @param User $user
     * @param int $page
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getMessages(ChatRoom $room, User $user, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        // Optimized: Use left join to exclude deleted messages more efficiently
        $query = Message::where('messages.chat_room_id', $room->id)
            ->leftJoin('message_deletions as md_me', function ($join) use ($user) {
                $join->on('md_me.message_id', '=', 'messages.id')
                    ->where('md_me.deleted_by_user_id', '=', $user->id)
                    ->where('md_me.deletion_type', '=', 'for_me');
            })
            ->leftJoin('message_deletions as md_all', function ($join) {
                $join->on('md_all.message_id', '=', 'messages.id')
                    ->where('md_all.deletion_type', '=', 'for_everyone');
            })
            ->whereNull('md_me.id')
            ->whereNull('md_all.id')
            ->select('messages.*')
            ->with([
                'user.profile',
                'replyTo.user.profile',
                // Only load reads for current user to reduce data
                'reads' => function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                },
            ])
            ->orderBy('messages.created_at', 'desc');

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}

