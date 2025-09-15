<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Notifications\FirebaseMessage;

class TestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $content,
        public readonly string $type = 'all'
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @param object $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        switch ($this->type) {
            case 'firebase':
                return ['firebase'];
            case 'database':
                return ['database'];
            case 'all':
            default:
                return ['database', 'firebase'];
        }
    }

    /**
     * Get the array representation of the notification.
     *
     * @param object $notifiable
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
            'type' => 'test',
            'timestamp' => now()->toISOString(),
            'message' => 'This is a test notification: ' . $this->content
        ];
    }

    /**
     * Get the Firebase Cloud Messaging representation of the notification.
     *
     * @param object $notifiable
     * @return FirebaseMessage
     */
    public function toFirebase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title($this->title)
            ->body($this->content)
            ->data([
                'type' => 'test',
                'timestamp' => now()->toISOString(),
                'user_id' => $notifiable->id,
                'notification_id' => $this->id ?? 'test-' . time()
            ]);
    }
}
