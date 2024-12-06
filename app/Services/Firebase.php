<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Storage;

class Firebase
{
    /**
     * Send notification to the user.
     *
     * @param string $title
     * @param string $body
     * @param string $token
     * @param array $data
     * @return array
     */
    public function send($title, $body, $token, $data = [])
    {
        $response = Http::withHeaders([
            'Authorization' => 'key=' . config('services.firebase.server_key'),
            'Content-Type' => 'application/json',
        ])->post('https://fcm.googleapis.com/fcm/send', [
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
            'to' => $token,
        ]);

        return $response->json();
    }
}
