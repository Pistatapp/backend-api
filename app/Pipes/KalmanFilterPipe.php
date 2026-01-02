<?php

namespace App\Pipes;

use App\Pipes\Contracts\GpsPathPipe;
use App\Services\KalmanFilter;

/**
 * Kalman Filter Pipe for GPS Path Correction
 *
 * Applies Kalman filtering to smooth GPS trajectories by estimating
 * the true position based on previous estimates and current measurements.
 *
 * This filter is effective for reducing noise and improving trajectory
 * smoothness in agricultural applications.
 */
class KalmanFilterPipe implements GpsPathPipe
{
    /**
     * Process noise parameter (default: 3.0 meters per second)
     * Higher values = more responsive to changes, less smoothing
     *
     * @var float
     */
    private float $processNoise;

    /**
     * Create a new KalmanFilterPipe instance
     *
     * @param float $processNoise Process noise in meters per second (default: 3.0)
     */
    public function __construct(float $processNoise = 3.0)
    {
        $this->processNoise = $processNoise;
    }

    /**
     * Process GPS points through Kalman filter
     *
     * @param array $gpsPoints Array of GPS points with 'lat' and 'lon' keys
     * @param \Closure $next Next pipe in the pipeline
     * @return array Filtered GPS points
     */
    public function handle(array $gpsPoints, \Closure $next): array
    {
        if (empty($gpsPoints)) {
            return $next($gpsPoints);
        }

        // Create fresh Kalman filter instance for this processing session
        // to avoid state pollution between different GPS paths
        $kalmanFilter = new KalmanFilter($this->processNoise);

        $filteredPoints = [];

        foreach ($gpsPoints as $point) {
            if (!isset($point['lat']) || !isset($point['lon'])) {
                // Skip invalid points
                $filteredPoints[] = $point;
                continue;
            }

            // Apply Kalman filter (it processes both lat and lon together)
            $filtered = $kalmanFilter->filter($point['lat'], $point['lon']);

            // Create filtered point preserving all original fields
            $filteredPoint = $point;
            $filteredPoint['lat'] = $filtered['latitude'];
            $filteredPoint['lon'] = $filtered['longitude'];

            // Update coordinate array if it exists
            if (isset($point['coordinate'])) {
                $filteredPoint['coordinate'] = [$filtered['latitude'], $filtered['longitude']];
            }

            $filteredPoints[] = $filteredPoint;
        }

        return $next($filteredPoints);
    }
}

