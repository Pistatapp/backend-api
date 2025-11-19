<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\AddUsersToChatRoomRequest;
use App\Http\Requests\Chat\CreateGroupChatRequest;
use App\Http\Requests\Chat\CreatePrivateChatRequest;
use App\Http\Requests\Chat\UpdateChatRoomRequest;
use App\Http\Resources\ChatRoomListResource;
use App\Http\Resources\ChatRoomResource;
use App\Models\ChatRoom;
use App\Models\Farm;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ChatRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatRoomController extends Controller
{
    protected ChatRoomService $chatRoomService;
    protected AuditLogService $auditLogService;

    public function __construct(
        ChatRoomService $chatRoomService,
        AuditLogService $auditLogService
    ) {
        $this->chatRoomService = $chatRoomService;
        $this->auditLogService = $auditLogService;
    }

    /**
     * Display a listing of chat rooms for a farm.
     */
    public function index(Request $request, Farm $farm): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', ChatRoom::class);

        // Ensure user is a member of the farm
        abort_unless($farm->users->contains($request->user()), 403, 'You are not a member of this farm.');

        $chatRooms = $this->chatRoomService->getUserChatRooms($request->user(), $farm);

        return ChatRoomListResource::collection($chatRooms);
    }

    /**
     * Create a private chat room.
     */
    public function createPrivate(CreatePrivateChatRequest $request, Farm $farm): ChatRoomResource
    {
        $this->authorize('create', ChatRoom::class);

        $targetUser = User::findOrFail($request->user_id);
        $chatRoom = $this->chatRoomService->createPrivateChatRoom($farm, $request->user(), $targetUser);

        $this->auditLogService->logChatAction('chat_room_created', $chatRoom, [
            'type' => 'private',
            'target_user_id' => $targetUser->id,
        ]);

        return new ChatRoomResource($chatRoom->load(['users', 'lastMessage']));
    }

    /**
     * Create a group chat room.
     */
    public function createGroup(CreateGroupChatRequest $request, Farm $farm): ChatRoomResource
    {
        $this->authorize('create', ChatRoom::class);

        $chatRoom = $this->chatRoomService->createGroupChatRoom(
            $farm,
            $request->name,
            $request->user_ids,
            $request->user()
        );

        $this->auditLogService->logChatAction('chat_room_created', $chatRoom, [
            'type' => 'group',
            'user_ids' => $request->user_ids,
        ]);

        return new ChatRoomResource($chatRoom->load(['users', 'lastMessage']));
    }

    /**
     * Display the specified chat room.
     */
    public function show(ChatRoom $chatRoom): ChatRoomResource
    {
        $this->authorize('view', $chatRoom);

        return new ChatRoomResource($chatRoom->load(['users', 'lastMessage', 'creator']));
    }

    /**
     * Update the specified chat room.
     */
    public function update(UpdateChatRoomRequest $request, ChatRoom $chatRoom): ChatRoomResource
    {
        $this->authorize('update', $chatRoom);

        $chatRoom->update($request->only('name'));

        $this->auditLogService->logChatAction('chat_room_updated', $chatRoom, [
            'updated_fields' => array_keys($request->only('name')),
        ]);

        return new ChatRoomResource($chatRoom->fresh()->load(['users', 'lastMessage']));
    }

    /**
     * Remove the user from the chat room (leave).
     */
    public function destroy(Request $request, ChatRoom $chatRoom)
    {
        $this->authorize('view', $chatRoom);

        // User can only leave, not delete the room (unless they're admin/creator)
        if ($chatRoom->created_by === $request->user()->id || $chatRoom->farm->users()
            ->where('users.id', $request->user()->id)
            ->wherePivot('role', 'admin')
            ->exists()) {
            // Admin or creator can delete the room
            $this->authorize('delete', $chatRoom);
            $chatRoom->delete();
        } else {
            // Regular user can only leave
            $this->chatRoomService->removeUserFromChatRoom($chatRoom, $request->user());
        }

        $this->auditLogService->logChatAction('chat_room_left', $chatRoom, [
            'user_id' => $request->user()->id,
        ]);

        return response()->noContent();
    }

    /**
     * Add users to a chat room.
     */
    public function addUsers(AddUsersToChatRoomRequest $request, ChatRoom $chatRoom): ChatRoomResource
    {
        $this->authorize('addUsers', $chatRoom);

        $this->chatRoomService->addUsersToChatRoom($chatRoom, $request->user_ids);

        $this->auditLogService->logChatAction('users_added_to_chat_room', $chatRoom, [
            'user_ids' => $request->user_ids,
        ]);

        return new ChatRoomResource($chatRoom->fresh()->load(['users', 'lastMessage']));
    }

    /**
     * Remove a user from a chat room.
     */
    public function removeUser(Request $request, ChatRoom $chatRoom, User $user)
    {
        $this->authorize('removeUsers', $chatRoom);

        $this->chatRoomService->removeUserFromChatRoom($chatRoom, $user);

        $this->auditLogService->logChatAction('user_removed_from_chat_room', $chatRoom, [
            'removed_user_id' => $user->id,
        ]);

        return response()->noContent();
    }

    /**
     * Mark chat room as read.
     */
    public function markRead(Request $request, ChatRoom $chatRoom): JsonResponse
    {
        $this->authorize('view', $chatRoom);

        $this->chatRoomService->markChatRoomAsRead($chatRoom, $request->user());

        return response()->json(['message' => 'Chat room marked as read']);
    }

    /**
     * Send typing indicator.
     */
    public function typing(Request $request, ChatRoom $chatRoom): JsonResponse
    {
        $this->authorize('view', $chatRoom);

        $request->validate([
            'is_typing' => 'required|boolean',
        ]);

        event(new \App\Events\UserTyping($chatRoom, $request->user(), $request->is_typing));

        return response()->json(['message' => 'Typing indicator sent']);
    }
}

