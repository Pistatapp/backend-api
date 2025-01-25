<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
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
     * Determine if the notification should be sent.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (app()->environment('local')) {
            Log::info('Mobile verification token: ' . $this->token);
            return false;
        }
        return true;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function toKavenegar($notifiable): KavenegarMessage
    {
        return (new KavenegarMessage)->verifyLookup('verifyPistat', $this->token);
    }
}
