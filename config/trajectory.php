<?php

return [
    // These are deliberately conservative starting values. Calibrate per device
    // class from read-only Production telemetry before changing them.
    'profiles' => [
        'UNKNOWN' => [
            'noise_radius_meters' => (float) env('GPS_TRAJECTORY_UNKNOWN_NOISE_RADIUS_M', 15.0),
            'max_plausible_speed_kmh' => (float) env('GPS_TRAJECTORY_UNKNOWN_MAX_SPEED_KMH', 45.0),
            'gap_seconds' => (int) env('GPS_TRAJECTORY_UNKNOWN_GAP_SECONDS', 600),
        ],
        'HOOSHNICS_STANDARD' => [
            'noise_radius_meters' => (float) env('GPS_TRAJECTORY_HOOSHNICS_NOISE_RADIUS_M', 15.0),
            'max_plausible_speed_kmh' => (float) env('GPS_TRAJECTORY_HOOSHNICS_MAX_SPEED_KMH', 45.0),
            'gap_seconds' => (int) env('GPS_TRAJECTORY_HOOSHNICS_GAP_SECONDS', 600),
        ],
        'TELTONIKA' => [
            'noise_radius_meters' => (float) env('GPS_TRAJECTORY_TELTONIKA_NOISE_RADIUS_M', 8.0),
            'max_plausible_speed_kmh' => (float) env('GPS_TRAJECTORY_TELTONIKA_MAX_SPEED_KMH', 45.0),
            'gap_seconds' => (int) env('GPS_TRAJECTORY_TELTONIKA_GAP_SECONDS', 600),
        ],
    ],
    'stationary' => [
        'minimum_window_seconds' => (int) env('GPS_TRAJECTORY_STATIONARY_WINDOW_SECONDS', 60),
        'minimum_points' => (int) env('GPS_TRAJECTORY_STATIONARY_MIN_POINTS', 3),
        'window_seconds' => (int) env('GPS_TRAJECTORY_STATIONARY_ROLLING_SECONDS', 180),
        'maximum_window_points' => (int) env('GPS_TRAJECTORY_STATIONARY_MAX_POINTS', 48),
        'low_speed_kmh' => (float) env('GPS_TRAJECTORY_LOW_SPEED_KMH', 2.0),
        'p95_multiplier' => (float) env('GPS_TRAJECTORY_STATIONARY_P95_MULTIPLIER', 1.0),
    ],
    'movement' => [
        'minimum_progression_points' => (int) env('GPS_TRAJECTORY_MIN_PROGRESSION_POINTS', 3),
        'minimum_net_displacement_multiplier' => (float) env('GPS_TRAJECTORY_MIN_NET_DISPLACEMENT_MULTIPLIER', 1.25),
        'minimum_directional_consistency' => (float) env('GPS_TRAJECTORY_MIN_DIRECTIONAL_CONSISTENCY', 0.55),
    ],
];
