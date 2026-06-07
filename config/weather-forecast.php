<?php

return [
    'api' => [
        'openmeteo' => [
            'domain' => 'customer-api.open-meteo.com',
            'key' => env('OPENMETEO_API_KEY'),
        ],
        'weatherapi' => [
            'domain' => 'api.weatherapi.com',
            'key' => env('WEATHERAPI_API_KEY'),
        ],
    ],
    'default' => 'openmeteo',
];
