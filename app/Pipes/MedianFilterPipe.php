<?php

namespace App\Pipes;

use App\Pipes\Contracts\GpsPathPipe;

/**
 * Median Filter Pipe for GPS Path Correction
 *
 * Removes outliers and noise by replacing each point with the median
 * of its neighboring points within a sliding window.
 *
 * This is particularly effective for removing GPS "jumps" and outliers
 * that occur in agricultural field environments.
 */
class MedianFilterPipe implements GpsPathPipe
{
    /**
     * Window size for median calculation (must be odd number)
     *
     * @var int
     */
    private int $windowSize;

    /**
     * Create a new MedianFilterPipe instance
     *
     * @param int $windowSize Window size for median calculation (default: 5, must be odd)
     */
    public function __construct(int $windowSize = 5)
    {
        // Ensure window size is odd
        $this->windowSize = $windowSize % 2 === 0 ? $windowSize + 1 : $windowSize;

        // Minimum window size is 3
        if ($this->windowSize < 3) {
            $this->windowSize = 3;
        }
    }

    /**
     * Process GPS points through median filter
     *
     * @param array $gpsPoints Array of GPS points with 'lat' and 'lon' keys
     * @param \Closure $next Next pipe in the pipeline
     * @return array Filtered GPS points
     */
    public function handle(array $gpsPoints, \Closure $next): array
    {
        if (count($gpsPoints) < $this->windowSize) {
            // Not enough points to filter, pass through
            return $next($gpsPoints);
        }

        $filteredPoints = [];
        $halfWindow = (int) floor($this->windowSize / 2);
        $pointCount = count($gpsPoints);

        for ($i = 0; $i < $pointCount; $i++) {
            // Get window boundaries
            $startIdx = max(0, $i - $halfWindow);
            $endIdx = min($pointCount - 1, $i + $halfWindow);

            // Extract window points
            $windowPoints = array_slice($gpsPoints, $startIdx, $endIdx - $startIdx + 1);

            // Calculate median for latitude and longitude separately
            $latitudes = array_column($windowPoints, 'lat');
            $longitudes = array_column($windowPoints, 'lon');

            sort($latitudes);
            sort($longitudes);

            $medianLat = $latitudes[(int) floor(count($latitudes) / 2)];
            $medianLon = $longitudes[(int) floor(count($longitudes) / 2)];

            // Create filtered point preserving all original fields
            $filteredPoint = $gpsPoints[$i];
            $filteredPoint['lat'] = $medianLat;
            $filteredPoint['lon'] = $medianLon;

            // Preserve original coordinates if they exist
            if (isset($gpsPoints[$i]['coordinate'])) {
                $filteredPoint['coordinate'] = [$medianLat, $medianLon];
            }

            $filteredPoints[] = $filteredPoint;
        }

        return $next($filteredPoints);
    }
}

