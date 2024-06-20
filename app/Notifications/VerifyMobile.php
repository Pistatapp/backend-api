<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Kavenegar\Laravel\Notification\KavenegarBaseNotification;
use Kavenegar\Laravel\Message\KavenegarMessage;

class VerifyMobile extends KavenegarBaseNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private string $token
    ) {
        //
    }

    /**
     * Get the notification's delivery channels.
     */
    public function toKavenegar($notifiable)
    {
        return (new KavenegarMessage)->verifyLookup('verifyPistat', $this->token);
    }
}
