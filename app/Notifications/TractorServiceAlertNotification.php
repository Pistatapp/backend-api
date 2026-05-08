<?php

namespace App\Notifications;

use App\Models\Tractor;
use App\Services\WarningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TractorServiceAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $message;

    public function __construct(
        private Tractor $tractor,
        private float $intervalHours,
        private float $intervalKm
    ) {
        $this->message = app(WarningService::class)->formatWarningMessage('tractor_periodic_service', [
            'tractor_name' => $this->tractor->name,
            'interval_hours' => $this->intervalHours,
            'interval_km' => $this->intervalKm,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->message,
            'tractor_id' => $this->tractor->id,
            'interval_hours' => $this->intervalHours,
            'interval_km' => $this->intervalKm,
            'color' => 'warning',
        ];
    }
}
