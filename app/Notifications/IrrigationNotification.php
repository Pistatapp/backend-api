<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Services\FirebaseChannel;
use Illuminate\Contracts\Queue\ShouldQueue;

class IrrigationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $status
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [FirebaseChannel::class, 'database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    /**
     * Prepare the notification data.
     *
     * @return array
     */
    private function prepareNotificationData()
    {
        $title = __("Irrigation {$this->status}");
        $body = __("The irrigation has been {$this->status}.");

        return [
            'title' => $title,
            'body' => $body,
        ];
    }

    /**
     * Get the Firebase representation of the notification.
     *
     * @param object $notifiable
     * @return array
     */
    public function toFirebase($notifiable): array
    {
        $data = $this->prepareNotificationData();

        return array_merge($data, [
            'data' => [
                'type' => 'irrigation',
                'priority' => 'high',
                'title' => $data['title'],
                'body' => $data['body'],
            ]
        ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param object $notifiable
     * @return array
     */
    public function toArray($notifiable): array
    {
        return $this->prepareNotificationData();
    }
}
