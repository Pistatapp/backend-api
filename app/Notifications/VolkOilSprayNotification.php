<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\VolkOilSpray;
use App\Notifications\FirebaseMessage;

class VolkOilSprayNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private VolkOilSpray $volkOilSpray,
        private int $calculatedColdRequirement
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => __('Cold requirement not met for Volk Oil Spray. Calculated: ' . $this->calculatedColdRequirement),
            'volk_oil_spray_id' => $this->volkOilSpray->id,
        ];
    }

    /**
     * Get the Firebase representation of the notification.
     */
    public function toFirebase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Volk Oil Spray Notification'))
            ->body(__('Cold requirement not met for Volk Oil Spray. Calculated: ') . $this->calculatedColdRequirement)
            ->data([
                'priority' => 'high',
                'title' => __('Volk Oil Spray Notification'),
                'body' => __('Cold requirement not met for Volk Oil Spray. Calculated: ') . $this->calculatedColdRequirement,
            ]);
    }
}
