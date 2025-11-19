<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Message $message
    ) {
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'message.sent';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith()
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'chat_room_id' => $this->message->chat_room_id,
                'user_id' => $this->message->user_id,
                'message_type' => $this->message->message_type,
                'content' => $this->message->content,
                'file_name' => $this->message->file_name,
                'file_size' => $this->message->file_size,
                'file_mime_type' => $this->message->file_mime_type,
                'reply_to_message_id' => $this->message->reply_to_message_id,
                'edited_at' => $this->message->edited_at?->toIso8601String(),
                'created_at' => $this->message->created_at->toIso8601String(),
                'user' => [
                    'id' => $this->message->user->id,
                    'username' => $this->message->user->username,
                ],
            ],
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat-room.' . $this->message->chat_room_id),
        ];
    }
}

