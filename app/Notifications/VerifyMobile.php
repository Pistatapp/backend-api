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
     * A random password for authentication.
     *
     * @var int
     */
    protected int $password;

    /**
     * Create a new notification instance.
     */
    public function __construct() {}

    /**
     * Determine if the notification should be sent.
     */
    public function shouldSend($notifiable): bool
    {
        $this->password = $this->generateRandomPassword($notifiable);

        if (app()->isLocal()) {
            Log::info('Verification password: ' . $this->password);
            return false; // Prevent sending notification
        }

        return true;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function toKavenegar($notifiable): ?KavenegarMessage
    {
        return (new KavenegarMessage)->verifyLookup('verifyPistat', $this->password);
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

        $notifiable->forceFill([
            'password' => $password,
            'password_expires_at' => now()->addMinutes(2),
        ])->save();

        return $password;
    }
}
