<?php

namespace App\Services;

class KalmanFilter
{
    private float $q; // process noise
    private float $r; // measurement noise
    private float $x_lat; // estimated latitude
    private float $x_lon; // estimated longitude
    private float $p_lat; // estimation error for latitude
    private float $p_lon; // estimation error for longitude
    private bool $initialized;

    public function __construct(float $q = 0.000001, float $r = 0.01)
    {
        $this->q = $q;
        $this->r = $r;
        $this->initialized = false;
    }

    public function filter(float $latitude, float $longitude): array
    {
        if (!$this->initialized) {
            $this->x_lat = $latitude;
            $this->x_lon = $longitude;
            $this->p_lat = 1.0;
            $this->p_lon = 1.0;
            $this->initialized = true;
            return [
                'latitude' => $latitude,
                'longitude' => $longitude
            ];
        }

        // prediction phase
        $this->p_lat = $this->p_lat + $this->q;
        $this->p_lon = $this->p_lon + $this->q;

        // measurement update for latitude
        $k_lat = $this->p_lat / ($this->p_lat + $this->r);
        $this->x_lat = $this->x_lat + $k_lat * ($latitude - $this->x_lat);
        $this->p_lat = (1 - $k_lat) * $this->p_lat;

        // measurement update for longitude
        $k_lon = $this->p_lon / ($this->p_lon + $this->r);
        $this->x_lon = $this->x_lon + $k_lon * ($longitude - $this->x_lon);
        $this->p_lon = (1 - $k_lon) * $this->p_lon;

        return [
            'latitude' => $this->x_lat,
            'longitude' => $this->x_lon
        ];
    }

    public function reset(): void
    {
        $this->initialized = false;
    }
}
