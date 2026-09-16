<?php

namespace App\Notifications;

use App\Models\AppRelease;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AppReleasePublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly AppRelease $release)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (
            filled($notifiable->fcm_token)
            && filled(config('services.fcm.project_id'))
            && is_file(storage_path('app/json/firebase.json'))
        ) {
            $channels[] = 'firebase';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'action' => 'app_update',
            'title' => 'نسخه جدید پیستاط در دسترس است',
            'body' => "نسخه {$this->release->version} آماده دریافت است.",
            'message' => "نسخه {$this->release->version} آماده دریافت است.",
            'color' => 'info',
            'release_id' => (string) $this->release->id,
            'version' => $this->release->version,
        ];
    }

    public function toFirebase(object $notifiable): FirebaseMessage
    {
        $title = 'نسخه جدید پیستاط در دسترس است';
        $body = "نسخه {$this->release->version} آماده دریافت است.";

        return (new FirebaseMessage)
            ->title($title)
            ->body($body)
            ->data([
                'action' => 'app_update',
                'title' => $title,
                'body' => $body,
                'release_id' => (string) $this->release->id,
                'version' => $this->release->version,
            ]);
    }
}
