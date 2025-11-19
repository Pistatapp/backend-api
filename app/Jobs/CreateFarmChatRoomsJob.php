<?php

namespace App\Jobs;

use App\Models\Farm;
use App\Services\ChatRoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateFarmChatRoomsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Farm $farm
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(ChatRoomService $chatRoomService): void
    {
        // Create group chat room for the farm
        $farmUsers = $this->farm->users;
        $userIds = $farmUsers->pluck('id')->toArray();
        $owner = $farmUsers->where('pivot.is_owner', true)->first();

        if ($owner && count($userIds) > 0) {
            $chatRoomService->createGroupChatRoom(
                $this->farm,
                $this->farm->name . ' Group Chat',
                $userIds,
                $owner
            );
        }

        // Create private chat rooms between owner and all other users
        if ($owner) {
            foreach ($farmUsers as $user) {
                if ($user->id !== $owner->id) {
                    $chatRoomService->createPrivateChatRoom($this->farm, $owner, $user);
                }
            }
        }
    }
}

