<?php

return [
    'package_name' => env('APP_RELEASE_PACKAGE_NAME', 'com.pistat.android'),
    'max_file_size' => (int) env('APP_RELEASE_MAX_FILE_SIZE', 157286400), // 150 MiB
    'notifications_enabled' => filter_var(
        env('APP_RELEASE_NOTIFICATIONS_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN
    ),
];
