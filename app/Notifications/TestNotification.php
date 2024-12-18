<?php

namespace App\Notifications;

use App\Services\FirebaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FirebaseChannel::class];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, string>
     */
    public function toFirebase(object $notifiable): array
    {
        return [
            'title' => 'Test Notification',
            'body' => 'This is a test notification.',
            'data' => [
                'priority' => 'high',
            ],
        ];
    }
}
