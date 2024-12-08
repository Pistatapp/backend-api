<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Firebase;
use App\Http\Controllers\Controller;

class FcmController extends Controller
{
    protected $fcmService;

    public function __construct(Firebase $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    public function sendNotification()
    {
        $deviceTokens = ['DEVICE_TOKEN_1', 'DEVICE_TOKEN_2']; // Replace with actual device tokens
        $title = 'Sample Notification';
        $body = 'This is a test notification';
        $data = ['key' => 'value']; // Optional custom data

        $response = $this->fcmService->sendNotification($deviceTokens, $title, $body, $data);

        return response()->json($response);
    }
}
