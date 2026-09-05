<?php

namespace App\Notifications;

use App\Models\Tractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Kavenegar\Laravel\Message\KavenegarMessage;
use Kavenegar\Laravel\Notification\KavenegarBaseNotification;

/**
 * SMS sent to a farm manager when a connected tractor has not produced a GPS
 * log by the daily 09:00 Tehran cutoff.
 *
 * The Kavenegar lookup template is deliberately configured outside the app so
 * the provider's approved Persian wording remains the source of truth.
 */
class TractorNotStartedTodayNotification extends KavenegarBaseNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Tractor $tractor)
    {
    }

    public function via($notifiable): array
    {
        return ['kavenegar'];
    }

    public function toKavenegar($notifiable): KavenegarMessage
    {
        return (new KavenegarMessage)->verifyLookup(
            config('kavenegar.tractor_not_started_template'),
            [$this->tractor->name]
        );
    }
}
