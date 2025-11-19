<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\DeleteMessageRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\UploadFileRequest;
use App\Http\Resources\MessageReadResource;
use App\Http\Resources\MessageResource;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageController extends Controller
{
    protected MessageService $messageService;

    public function __construct(MessageService $messageService)
    {
        $this->messageService = $messageService;
    }

    /**
     * Display a listing of messages for a chat room.
     */
    public function index(Request $request, ChatRoom $chatRoom): AnonymousResourceCollection
    {
        $this->authorize('view', $chatRoom);
        $this->authorize('viewMessages', Message::make(['chat_room_id' => $chatRoom->id]));

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 50);

        $messages = $this->messageService->getMessages($chatRoom, $request->user(), $page, $perPage);

        return MessageResource::collection($messages);
    }

    /**
     * Store a newly created message.
     */
    public function store(SendMessageRequest $request, ChatRoom $chatRoom): MessageResource
    {
        $this->authorize('send', $chatRoom);

        if ($request->hasFile('file')) {
            $message = $this->messageService->sendFileMessage(
                $chatRoom,
                $request->user(),
                $request->file('file')
            );
        } else {
            $message = $this->messageService->sendTextMessage(
                $chatRoom,
                $request->user(),
                $request->input('content'),
                $request->input('reply_to_message_id')
            );
        }

        // Broadcast event will be handled by event listener
        return new MessageResource($message->load(['user', 'replyTo']));
    }

    /**
     * Upload a file message.
     */
    public function uploadFile(UploadFileRequest $request, ChatRoom $chatRoom): MessageResource
    {
        $this->authorize('send', $chatRoom);

        $message = $this->messageService->sendFileMessage(
            $chatRoom,
            $request->user(),
            $request->file('file')
        );

        return new MessageResource($message->load(['user']));
    }

    /**
     * Update the specified message.
     */
    public function update(Request $request, Message $message): MessageResource
    {
        $this->authorize('update', $message);

        $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $updatedMessage = $this->messageService->editMessage($message, $request->input('content'));

        return new MessageResource($updatedMessage->load(['user', 'replyTo']));
    }

    /**
     * Delete the specified message.
     */
    public function destroy(DeleteMessageRequest $request, Message $message): JsonResponse
    {
        $this->authorize('delete', $message);

        if ($request->deletion_type === 'for_everyone') {
            $this->authorize('deleteForEveryone', $message);
        }

        $this->messageService->deleteMessage($message, $request->user(), $request->deletion_type);

        return response()->json(['message' => 'Message deleted successfully']);
    }

    /**
     * Mark a message as read.
     */
    public function markRead(Request $request, Message $message): JsonResponse
    {
        $this->authorize('view', $message);

        $messageRead = $this->messageService->markMessageAsRead($message, $request->user());

        return response()->json(new MessageReadResource($messageRead->load('user')));
    }

    /**
     * Get read status for a message.
     */
    public function readStatus(Request $request, Message $message): AnonymousResourceCollection
    {
        $this->authorize('view', $message);

        $reads = $this->messageService->getMessageReadStatus($message);

        return MessageReadResource::collection($reads);
    }

    /**
     * Search messages in a chat room.
     */
    public function search(Request $request, ChatRoom $chatRoom): AnonymousResourceCollection
    {
        $this->authorize('view', $chatRoom);

        $request->validate([
            'q' => 'required|string|min:2|max:255',
        ]);

        $query = $request->input('q');
        $user = $request->user();

        // Optimized: Use left join for better performance on search queries
        $messages = Message::where('messages.chat_room_id', $chatRoom->id)
            ->where('messages.content', 'like', "%{$query}%")
            ->where('messages.message_type', 'text')
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
            ->with(['user.profile', 'replyTo.user.profile'])
            ->orderBy('messages.created_at', 'desc')
            ->paginate(20);

        return MessageResource::collection($messages);
    }
}

