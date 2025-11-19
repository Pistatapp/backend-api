<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ChatRoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ArchiveUserChatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public User $user
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(ChatRoomService $chatRoomService): void
    {
        // Mark user as left in all chat rooms
        foreach ($this->user->activeChatRooms as $chatRoom) {
            $chatRoomService->removeUserFromChatRoom($chatRoom, $this->user);
        }

        // Update user's online status
        $this->user->update([
            'is_online' => false,
            'last_seen_at' => now(),
        ]);
    }
}

