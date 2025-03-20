<?php

namespace App\Traits;

trait DistanceCalculator
{
    /**
     * Calculate the distance between two points using the Haversine formula.
     *
     * @param  array  $point1  ['latitude' => float, 'longitude' => float]
     * @param  array  $point2  ['latitude' => float, 'longitude' => float]
     * @return float  Distance in kilometers
     */
    public function calculateDistance(array $point1, array $point2): float
    {
        $earthRadiusKm = 6371;

        $lat1 = deg2rad($point1['latitude']);
        $lng1 = deg2rad($point1['longitude']);
        $lat2 = deg2rad($point2['latitude']);
        $lng2 = deg2rad($point2['longitude']);

        $deltaLat = $lat2 - $lat1;
        $deltaLng = $lng2 - $lng1;

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
            cos($lat1) * cos($lat2) *
            sin($deltaLng / 2) * sin($deltaLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
