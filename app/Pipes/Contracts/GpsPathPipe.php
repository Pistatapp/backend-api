<?php

namespace App\Pipes\Contracts;

/**
 * Contract for GPS path correction pipes
 *
 * Each pipe receives GPS data points and returns processed data points.
 * GPS data point format: ['lat' => float, 'lon' => float, ...other fields]
 */
interface GpsPathPipe
{
    /**
     * Process GPS data points through this pipe
     *
     * @param array $gpsPoints Array of GPS points, each point is an array with at least 'lat' and 'lon' keys
     * @param \Closure $next Closure to pass data to the next pipe
     * @return array Processed GPS points
     */
    public function handle(array $gpsPoints, \Closure $next): array;
}

