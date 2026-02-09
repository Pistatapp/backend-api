<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Kavenegar\Laravel\Notification\KavenegarBaseNotification;
use Kavenegar\Laravel\Message\KavenegarMessage;
use Illuminate\Support\Facades\Log;

class VerifyMobile extends KavenegarBaseNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct() {}

    /**
     * Get the notification's delivery channels.
     */
    public function toKavenegar($notifiable): ?KavenegarMessage
    {
        $password = $this->generateRandomPassword($notifiable);
        return (new KavenegarMessage)->verifyLookup('verifyPistat', $password);
    }

    /**
     * Generate a random password for the user.
     *
     * @param mixed $notifiable
     * @return int
     */
    protected function generateRandomPassword($notifiable): int
    {
        $password = random_int(100000, 999999);

        Log::info('Generated password: ' . $password);

        $notifiable->forceFill([
            'password' => $password,
            'password_expires_at' => now()->addMinutes(2),
        ])->save();

        return $password;
    }
}
