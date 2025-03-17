<?php

namespace App\Services;

class KalmanFilter
{
    private $Q_metres_per_second;    // process noise
    private $R_metres;               // measurement noise
    private $variance_metres;        // estimation variance
    private $last_lat;              // last latitude estimate
    private $last_lng;              // last longitude estimate

    public function __construct($noise = 3.0)
    {
        $this->Q_metres_per_second = $noise;
        $this->R_metres = 6.0;
        $this->variance_metres = -1;
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
