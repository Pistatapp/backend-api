<?php

namespace App\Services;

/**
 * Rejects impossible short-interval GPS jumps from derived calculations.
 *
 * Raw gps_data rows are never removed or rewritten. Consumers keep the last
 * accepted point as their predecessor so one bad frame cannot poison the next
 * transition as well.
 */
class GpsLogContinuityGuard
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    public function isOutlier(
        float $previousLatitude,
        float $previousLongitude,
        ?int $previousTimestamp,
        float $currentLatitude,
        float $currentLongitude,
        ?int $currentTimestamp,
    ): bool {
        if ($previousTimestamp === null || $currentTimestamp === null) {
            return false;
        }

        $elapsedSeconds = $currentTimestamp - $previousTimestamp;
        if ($elapsedSeconds <= 0) {
            return false;
        }

        $distance = $this->distanceMeters(
            $previousLatitude,
            $previousLongitude,
            $currentLatitude,
            $currentLongitude,
        );
        $speedAllowance = $elapsedSeconds / 3600 * $this->maximumPlausibleSpeedKmh() * 1000;
        $allowedDistance = min(
            $this->maximumRelocationMeters(),
            max($this->maximumDistanceMeters(), $speedAllowance),
        );

        return $distance > $allowedDistance;
    }

    private function maximumDistanceMeters(): float
    {
        return (float) config('trajectory.continuity.max_distance_meters', 1000.0);
    }

    private function maximumPlausibleSpeedKmh(): float
    {
        return max(1.0, (float) config('trajectory.continuity.max_plausible_speed_kmh', 45.0));
    }

    private function maximumRelocationMeters(): float
    {
        return max(
            $this->maximumDistanceMeters(),
            (float) config('trajectory.continuity.max_relocation_meters', 5000.0),
        );
    }

    private function distanceMeters(
        float $latitudeA,
        float $longitudeA,
        float $latitudeB,
        float $longitudeB,
    ): float {
        $latitudeARadians = deg2rad($latitudeA);
        $longitudeARadians = deg2rad($longitudeA);
        $latitudeBRadians = deg2rad($latitudeB);
        $longitudeBRadians = deg2rad($longitudeB);
        $latitudeDelta = $latitudeBRadians - $latitudeARadians;
        $longitudeDelta = $longitudeBRadians - $longitudeARadians;
        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($latitudeARadians) * cos($latitudeBRadians) * sin($longitudeDelta / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(
            sqrt($haversine),
            sqrt(max(0.0, 1.0 - $haversine)),
        );
    }
}
