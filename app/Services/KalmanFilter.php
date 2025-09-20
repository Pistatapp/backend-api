<?php

namespace App\Services;

class KalmanFilter
{
    /**
     * @var float $Q_metres_per_second
     * Process noise covariance (Q): Represents the expected variance in the process (i.e., how much the true position is expected to change between measurements).
     */
    private $Q_metres_per_second;

    /**
     * @var float $R_metres
     * Measurement noise covariance (R): Represents the expected variance in the measurement (i.e., the accuracy of the GPS sensor).
     */
    private $R_metres;

    /**
     * @var float $variance_metres
     * Estimation variance: The current estimate of the error variance in the filtered position.
     */
    private $variance_metres;

    /**
     * @var float $last_lat
     * Last latitude estimate: The most recent filtered latitude value.
     */
    private $last_lat;

    /**
     * @var float $last_lng
     * Last longitude estimate: The most recent filtered longitude value.
     */
    private $last_lng;

    public function __construct($noise = 3.0)
    {
        $this->Q_metres_per_second = $noise;
        $this->R_metres = 6.0;
        $this->variance_metres = 1;
        $this->last_lat = 0.0;
        $this->last_lng = 0.0;
    }

    public function filter($lat, $lng): array
    {
        if ($this->variance_metres < 0) {
            // Initial state
            $this->last_lat = $lat;
            $this->last_lng = $lng;
            $this->variance_metres = $this->R_metres;
            return ['latitude' => $lat, 'longitude' => $lng];
        }

        // Project state ahead
        $prediction_variance = $this->variance_metres + $this->Q_metres_per_second;

        // Calculate Kalman gain
        $kalman_gain = $prediction_variance / ($prediction_variance + $this->R_metres);

        // Update state estimate
        $this->last_lat = $this->last_lat + $kalman_gain * ($lat - $this->last_lat);
        $this->last_lng = $this->last_lng + $kalman_gain * ($lng - $this->last_lng);

        // Update variance estimate
        $this->variance_metres = (1 - $kalman_gain) * $prediction_variance;

        return [
            'latitude' => $this->last_lat,
            'longitude' => $this->last_lng
        ];
    }

    public function reset(): void
    {
        $this->variance_metres = -1;
        $this->last_lat = 0.0;
        $this->last_lng = 0.0;
    }
}
