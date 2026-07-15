<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'weatherapi' => [
        'key' => env('WEATHER_API_KEY'),
        'proxy' => env('WEATHER_API_PROXY'),
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'key' => env('FCM_SERVER_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zarinpal Payment Gateway
    |--------------------------------------------------------------------------
    |
    | This is the configuration for Zarinpal payment gateway.
    |
    */
    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID', '00000000-0000-0000-0000-000000000000'),
        'currency' => env('ZARINPAL_CURRENCY', 'IRT'), // IRT for Toman, IRR for Rial
    ],

    'gps_reports' => [
        'rate_limit_exempt_ips' => array_filter(array_map(
            'trim',
            explode(',', env('GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS', '94.101.187.206'))
        )),
    ],

    'gps_ingest' => [
        'driver' => env('GPS_INGEST_DRIVER', 'laravel'),
        'go_url' => env('GPS_INGEST_GO_URL', 'http://127.0.0.1:8081'),
    ],

];
