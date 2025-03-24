<?php

namespace App\Notifications;

use App\Models\NutrientDiagnosisRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Notifications\FirebaseMessage;

class NutrientDiagnosisResponse extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The nutrient diagnosis request that has been answered.
     *
     * @var \App\Models\NutrientDiagnosisRequest
     */
    private $request;

    /**
     * Create a new notification instance.
     *
     * @param \App\Models\NutrientDiagnosisRequest $request
     */
    public function __construct(NutrientDiagnosisRequest $request)
    {
        $this->request = $request;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<string>
     */
    public function via($notifiable): array
    {
        return ['database', 'firebase'];
    }

    /**
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, string>
     */
    public function toDatabase($notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'message' => 'Your Nutrient Diagnosis request has been answered',
            'description' => $this->request->response_description,
        ];
    }

    /**
     * Get the Firebase representation of the notification.
     *
     * @return \App\Notifications\FirebaseMessage
     */
    public function toFirebase($notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title('Nutrient Diagnosis Response')
            ->body('Your request has received a response')
            ->data([
                'request_id' => (string) $this->request->id,
                'type' => 'nutrient_diagnosis_response',
                'user_name' => $this->request->user->name,
                'message' => 'Your Nutrient Diagnosis request has been answered',
            ]);
    }
}
