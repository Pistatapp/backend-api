<?php

namespace App\Services;

use GuzzleHttp\Client;

class Firebase
{
    protected $serverKey;
    protected $apiUrl;

    public function __construct()
    {
        $this->serverKey = env('FIREBASE_SERVER_KEY');
        $this->apiUrl = 'https://fcm.googleapis.com/fcm/send';
    }

    public function sendNotification($deviceTokens, $title, $body, $data = [])
    {
        $client = new Client();

        $payload = [
            'registration_ids' => $deviceTokens, // Use 'to' for single device token
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
            ],
            'data' => $data, // Optional custom data
        ];

        $response = $client->post($this->apiUrl, [
            'headers' => [
                'Authorization' => 'key=' . $this->serverKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        return json_decode($response->getBody(), true);
    }
}
