<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Notifications\FirebaseMessage;

class CropTypeDegreeDayNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $cropType,
        public string $startDate,
        public string $endDate,
        public float $requiredDegreeDays,
        public float $actualDegreeDays
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'firebase'];
    }

    public function toFirebase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Crop Type Degree Day Warning'))
            ->body($this->getMessage())
            ->data([
                'crop_type' => $this->cropType,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'required_degree_days' => (string) $this->requiredDegreeDays,
                'actual_degree_days' => (string) $this->actualDegreeDays,
                'type' => 'crop_type_degree_day',
                'priority' => 'high'
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->getMessage(),
            'crop_type' => $this->cropType,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'required_degree_days' => $this->requiredDegreeDays,
            'actual_degree_days' => $this->actualDegreeDays,
        ];
    }

    private function getMessage(): string
    {
        return __('The degree days for :crop_type crop_type from :start_date to :end_date was :degree_days.', [
            'crop_type' => $this->cropType,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'degree_days' => $this->actualDegreeDays
        ]);
    }
}
