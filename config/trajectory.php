<?php

return [
    // These are deliberately conservative starting values. Calibrate per device
    // class from read-only Production telemetry before changing them.
    'profiles' => [
        'UNKNOWN' => [
            'noise_radius_meters' => (float) env('GPS_TRAJECTORY_UNKNOWN_NOISE_RADIUS_M', 15.0),
            'max_plausible_speed_kmh' => (float) env('GPS_TRAJECTORY_UNKNOWN_MAX_SPEED_KMH', 45.0),
            // A missing sample window is a real route break. Connecting the
            // points on either side can create a false diagonal on the map.
            // It is only the normal sampling-gap threshold; the service also
            // checks implied speed and displacement before breaking a route.
            'gap_seconds' => (int) env('GPS_TRAJECTORY_UNKNOWN_GAP_SECONDS', 180),
            'max_bridge_gap_seconds' => (int) env('GPS_TRAJECTORY_UNKNOWN_MAX_BRIDGE_GAP_SECONDS', 900),
            'long_gap_bridge_radius_meters' => (float) env('GPS_TRAJECTORY_UNKNOWN_LONG_GAP_BRIDGE_RADIUS_M', 75.0),
        ],
        'HOOSHNICS_STANDARD' => [
            'noise_radius_meters' => (float) env('GPS_TRAJECTORY_HOOSHNICS_NOISE_RADIUS_M', 15.0),
            'max_plausible_speed_kmh' => (float) env('GPS_TRAJECTORY_HOOSHNICS_MAX_SPEED_KMH', 45.0),
            'gap_seconds' => (int) env('GPS_TRAJECTORY_HOOSHNICS_GAP_SECONDS', 180),
            'max_bridge_gap_seconds' => (int) env('GPS_TRAJECTORY_HOOSHNICS_MAX_BRIDGE_GAP_SECONDS', 900),
            'long_gap_bridge_radius_meters' => (float) env('GPS_TRAJECTORY_HOOSHNICS_LONG_GAP_BRIDGE_RADIUS_M', 75.0),
        ],
        'TELTONIKA' => [
            'noise_radius_meters' => (float) env('GPS_TRAJECTORY_TELTONIKA_NOISE_RADIUS_M', 8.0),
            'max_plausible_speed_kmh' => (float) env('GPS_TRAJECTORY_TELTONIKA_MAX_SPEED_KMH', 45.0),
            'gap_seconds' => (int) env('GPS_TRAJECTORY_TELTONIKA_GAP_SECONDS', 180),
            'max_bridge_gap_seconds' => (int) env('GPS_TRAJECTORY_TELTONIKA_MAX_BRIDGE_GAP_SECONDS', 900),
            'long_gap_bridge_radius_meters' => (float) env('GPS_TRAJECTORY_TELTONIKA_LONG_GAP_BRIDGE_RADIUS_M', 75.0),
        ],
    ],
    'stationary' => [
        'minimum_window_seconds' => (int) env('GPS_TRAJECTORY_STATIONARY_WINDOW_SECONDS', 60),
        'minimum_points' => (int) env('GPS_TRAJECTORY_STATIONARY_MIN_POINTS', 3),
        'window_seconds' => (int) env('GPS_TRAJECTORY_STATIONARY_ROLLING_SECONDS', 180),
        'maximum_window_points' => (int) env('GPS_TRAJECTORY_STATIONARY_MAX_POINTS', 48),
        'low_speed_kmh' => (float) env('GPS_TRAJECTORY_LOW_SPEED_KMH', 2.0),
        // Some devices report a small non-zero speed while the engine is off.
        // Status 0 is used only within this conservative ceiling so a
        // contradictory high-speed row is not silently discarded.
        'engine_off_max_speed_kmh' => (float) env('GPS_TRAJECTORY_ENGINE_OFF_MAX_SPEED_KMH', 15.0),
        'p95_multiplier' => (float) env('GPS_TRAJECTORY_STATIONARY_P95_MULTIPLIER', 1.0),
    ],
    'movement' => [
        'minimum_progression_points' => (int) env('GPS_TRAJECTORY_MIN_PROGRESSION_POINTS', 3),
        'minimum_net_displacement_multiplier' => (float) env('GPS_TRAJECTORY_MIN_NET_DISPLACEMENT_MULTIPLIER', 1.25),
        'minimum_directional_consistency' => (float) env('GPS_TRAJECTORY_MIN_DIRECTIONAL_CONSISTENCY', 0.55),
    ],
];
