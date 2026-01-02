<?php

namespace App\Services;

use App\Pipes\KalmanFilterPipe;
use App\Pipes\MedianFilterPipe;
use Illuminate\Pipeline\Pipeline;

/**
 * GPS Path Corrector Service
 *
 * Uses Laravel's Pipeline pattern to process GPS data through multiple
 * correction filters (Median and Kalman filters).
 *
 * This service provides a clean, modular way to apply GPS path correction
 * algorithms to improve accuracy and reduce noise in agricultural applications.
 */
class GpsPathCorrectorService
{
    /**
     * Default pipes to apply in order
     *
     * @var array
     */
    private array $defaultPipes = [
        MedianFilterPipe::class,
        KalmanFilterPipe::class,
    ];

    /**
     * Correct GPS path using default filters (Median + Kalman)
     *
     * @param array $gpsPoints Array of GPS points, each with 'lat' and 'lon' keys
     * @param array|null $pipes Optional custom pipes array. If null, uses default pipes
     * @return array Corrected GPS points
     */
    public function correct(array $gpsPoints, ?array $pipes = null): array
    {
        if (empty($gpsPoints)) {
            return $gpsPoints;
        }

        // Normalize GPS points to ensure consistent format
        $normalizedPoints = $this->normalizePoints($gpsPoints);

        // Use provided pipes or default pipes
        $pipesToUse = $pipes ?? $this->defaultPipes;

        // Process through pipeline
        return app(Pipeline::class)
            ->send($normalizedPoints)
            ->through($pipesToUse)
            ->thenReturn();
    }

    /**
     * Correct GPS path using only Median filter
     *
     * @param array $gpsPoints Array of GPS points
     * @param int $windowSize Median filter window size (default: 5)
     * @return array Corrected GPS points
     */
    public function correctWithMedian(array $gpsPoints, int $windowSize = 5): array
    {
        return $this->correct($gpsPoints, [
            new MedianFilterPipe($windowSize),
        ]);
    }

    /**
     * Correct GPS path using only Kalman filter
     *
     * @param array $gpsPoints Array of GPS points
     * @param float $processNoise Kalman filter process noise (default: 3.0)
     * @return array Corrected GPS points
     */
    public function correctWithKalman(array $gpsPoints, float $processNoise = 3.0): array
    {
        return $this->correct($gpsPoints, [
            new KalmanFilterPipe($processNoise),
        ]);
    }

    /**
     * Normalize GPS points to ensure consistent format
     * Handles various input formats and converts to standard ['lat' => float, 'lon' => float, ...] format
     *
     * @param array $gpsPoints Raw GPS points in various formats
     * @return array Normalized GPS points
     */
    private function normalizePoints(array $gpsPoints): array
    {
        $normalized = [];

        foreach ($gpsPoints as $point) {
            $normalizedPoint = $point;

            // Handle coordinate array format [lat, lon]
            if (isset($point['coordinate']) && is_array($point['coordinate'])) {
                if (!isset($normalizedPoint['lat'])) {
                    $normalizedPoint['lat'] = $point['coordinate'][0] ?? 0.0;
                }
                if (!isset($normalizedPoint['lon'])) {
                    $normalizedPoint['lon'] = $point['coordinate'][1] ?? 0.0;
                }
            }

            // Ensure lat and lon are floats
            if (isset($normalizedPoint['lat'])) {
                $normalizedPoint['lat'] = (float) $normalizedPoint['lat'];
            }
            if (isset($normalizedPoint['lon'])) {
                $normalizedPoint['lon'] = (float) $normalizedPoint['lon'];
            }

            // If coordinate array doesn't exist but lat/lon do, create it
            if (!isset($normalizedPoint['coordinate']) && isset($normalizedPoint['lat']) && isset($normalizedPoint['lon'])) {
                $normalizedPoint['coordinate'] = [$normalizedPoint['lat'], $normalizedPoint['lon']];
            }

            $normalized[] = $normalizedPoint;
        }

        return $normalized;
    }

    /**
     * Get default pipes configuration
     *
     * @return array
     */
    public function getDefaultPipes(): array
    {
        return $this->defaultPipes;
    }

    /**
     * Set custom default pipes
     *
     * @param array $pipes Array of pipe classes
     * @return self
     */
    public function setDefaultPipes(array $pipes): self
    {
        $this->defaultPipes = $pipes;
        return $this;
    }
}

