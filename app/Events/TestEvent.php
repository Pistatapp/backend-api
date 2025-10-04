<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TestEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $title;
    public $content;
    public $type;
    public $timestamp;
    public $userId;

    /**
     * Create a new event instance.
     */
    public function __construct(string $title, string $content, string $type = 'public', ?int $userId = null)
    {
        $this->title = $title;
        $this->content = $content;
        $this->type = $type;
        $this->timestamp = now()->toISOString();
        $this->userId = $userId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return $this->type === 'private'
            ? [new PrivateChannel('users.' . $this->userId)]
            : [new Channel('test-channel')];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
            'type' => $this->type,
            'timestamp' => $this->timestamp,
            'user_id' => $this->userId,
            'message' => 'This is a test WebSocket broadcast: ' . $this->content
        ];
    }

    public function broadcastAs() {
        return 'test.notification';
    }
}
