<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GPS Report Processing Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for GPS report processing,
    | particularly for tractor working time detection and performance
    | optimization settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Working Time Detection Settings
    |--------------------------------------------------------------------------
    */

    // Minimum speed threshold (km/h) to consider a tractor as "moving"
    'speed_threshold' => env('GPS_SPEED_THRESHOLD', 2),

    // Number of reports to analyze in sliding window for pattern detection
    'window_size' => env('GPS_WINDOW_SIZE', 3),

    /*
    |--------------------------------------------------------------------------
    | Performance Optimization Settings
    |--------------------------------------------------------------------------
    */

    // Cache TTL for working time points data (minutes)
    'cache_ttl' => env('GPS_CACHE_TTL', 60),

    // Short cache TTL for reports data (minutes)
    'short_cache_ttl' => env('GPS_SHORT_CACHE_TTL', 5),

    // Time window around current report to query (minutes)
    // Reduces database load by only fetching nearby reports
    'query_window_minutes' => env('GPS_QUERY_WINDOW_MINUTES', 30),

    // Performance threshold for logging slow operations (seconds)
    'performance_threshold' => env('GPS_PERFORMANCE_THRESHOLD', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Database Optimization Settings
    |--------------------------------------------------------------------------
    */

    // Use read replicas for better performance (requires read replica setup)
    'use_read_replicas' => env('GPS_USE_READ_REPLICAS', false),

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Logging Settings
    |--------------------------------------------------------------------------
    */

    // Enable performance monitoring
    'enable_performance_monitoring' => env('GPS_ENABLE_PERFORMANCE_MONITORING', true),

    // Enable cache warming for better performance
    'enable_cache_warming' => env('GPS_ENABLE_CACHE_WARMING', true),

    /*
      |--------------------------------------------------------------------------
      | Determines whether GPS report storage should be processed through a queue job.
      | If set to true, incoming GPS data will be dispatched to a background job (StoreGpsReportJob)
      | for asynchronous database insertion. If false, the data will be stored immediately within the request.
      |--------------------------------------------------------------------------
      */
    'use_queue' => env('GPS_USE_QUEUE', false),
];
