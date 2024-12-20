<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;

class FirebaseChannel
{
    /**
     * Firebase Cloud Messaging server key
     *
     * @var string
     */
    protected $serverKey;

    /**
     * Firebase Cloud Messaging project ID
     *
     * @var string
     */
    protected $projectId;

    public function __construct()
    {
        $this->serverKey = config('services.fcm.key');
        $this->projectId = config('services.fcm.project_id');
    }

    /**
     * Send the given notification.
     *
     * @param object $notifiable
     * @param \Illuminate\Notifications\Notification $notification
     * @return void
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $firebaseMessage = $notification->toFirebase($notifiable);

        $fcmToken = $notifiable->fcm_token;
        $title = $firebaseMessage->title;
        $body = $firebaseMessage->body;
        $additionalData = $firebaseMessage->data;

        $this->sendNotification($fcmToken, $title, $body, $additionalData);
    }

    /**
     * Send notification to Firebase Cloud Messaging
     *
     * @param string $fcmToken
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function sendNotification($fcmToken, $title, $body, $data = [])
    {
        $accessToken = $this->getAccessToken();

        $headers = $this->prepareHeaders($accessToken);
        $payload = $this->preparePayload($fcmToken, $title, $body, $data);

        $response = $this->sendRequest($headers, $payload);

        return $this->handleResponse($response);
    }

    /**
     * Get access token from Google API
     *
     * @return string
     * @throws \Exception
     */
    protected function getAccessToken()
    {
        $credentialsFilePath = storage_path('app/json/firebase.json');
        $client = new GoogleClient();
        $client->setAuthConfig($credentialsFilePath);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->fetchAccessTokenWithAssertion();
        $token = $client->getAccessToken();

        return $token['access_token'];
    }

    /**
     * Prepare headers for the request
     *
     * @param string $accessToken
     * @return array
     */
    protected function prepareHeaders($accessToken)
    {
        return [
            "Authorization" => "Bearer $accessToken",
            'Content-Type' => 'application/json'
        ];
    }

    /**
     * Prepare payload for the request
     *
     * @param array|string $fcmToken
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    protected function preparePayload($fcmToken, $title, $body, $data)
    {
        return [
            "message" => [
                "token" => $fcmToken,
                "notification" => [
                    "title" => $title,
                    "body" => $body,
                ],
                'data' => $data,
            ]
        ];
    }

    /**
     * Send request to Firebase Cloud Messaging
     *
     * @param array $headers
     * @param array $payload
     * @return \Illuminate\Http\Client\Response
     */
    protected function sendRequest($headers, $payload)
    {
        return Http::withHeaders($headers)
            ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", $payload);
    }

    /**
     * Handle response from Firebase Cloud Messaging
     *
     * @param \Illuminate\Http\Client\Response $response
     * @return array
     * @throws \Exception
     */
    protected function handleResponse($response)
    {
        if ($response->failed()) {
            throw new \Exception('Firebase notification failed: ' . $response->body());
        }

        return $response->json();
    }
}
