<?php

namespace App\Jobs;

use App\Models\AppRelease;
use App\Models\User;
use App\Notifications\AppReleasePublished;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAppReleaseNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $releaseId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $release = AppRelease::query()->find($this->releaseId);
        if ($release === null) {
            return;
        }

        User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->eachById(function (User $user) use ($release): void {
                try {
                    $user->notify(new AppReleasePublished($release));
                } catch (\Throwable $exception) {
                    // One malformed/stale device token must not prevent the
                    // rest of the users from receiving the release notice.
                    Log::warning('Unable to send application release notification.', [
                        'release_id' => $release->id,
                        'user_id' => $user->id,
                        'exception' => $exception,
                    ]);
                }
            });
    }
}
