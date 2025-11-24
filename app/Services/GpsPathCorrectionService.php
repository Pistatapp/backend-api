<?php

namespace App\Services;

/**
 * GPS path-correction algorithms focused on geometric smoothing.
 *
 * This service exposes a streaming algorithm that selectively smooths only sharp
 * corners and turn-arounds without disturbing straight segments. It keeps memory
 * usage O(1) by buffering just three points at a time.
 */
class GpsPathCorrectionService
{
    /**
     * Minimum angle (in degrees) between consecutive segments required to apply smoothing.
     * Angles are computed such that 0° = straight line, 180° = full turn-around.
     */
    private float $cornerAngleThresholdDeg = 35.0;

    /**
     * Strength of the correction applied to qualifying corner points (0-1).
     */
    private float $cornerSmoothFactor = 0.55;

    /**
     * Ignore extremely short segments (meters) to avoid amplifying GPS noise.
     */
    private float $minSegmentDistanceMeters = 0.5;

    /**
     * Stream-based corner smoothing. Only middle points that represent sharp turns
     * or turn-arounds are adjusted; other points are passed through untouched.
     *
     * @param \Traversable $points
     * @return \Generator
     */
    public function smoothCornersStream($points): \Generator
    {
        $buffer = [];

        foreach ($points as $point) {
            $this->normalizePointCoordinate($point);
            $buffer[] = $point;

            if (count($buffer) < 3) {
                continue;
            }

            [$prev, $curr, $next] = $buffer;
            $smoothed = $this->applyCornerSmoothing($prev, $curr, $next);
            yield $smoothed;

            array_shift($buffer);
        }

        foreach ($buffer as $remaining) {
            yield $remaining;
        }
    }

    /**
     * Apply selective smoothing to the middle point of three consecutive samples.
     */
    private function applyCornerSmoothing(object $prev, object $curr, object $next): object
    {
        $prevCoord = $this->normalizePointCoordinate($prev);
        $currCoord = $this->normalizePointCoordinate($curr);
        $nextCoord = $this->normalizePointCoordinate($next);

        $prevDist = $this->distanceMeters($prevCoord, $currCoord);
        $nextDist = $this->distanceMeters($currCoord, $nextCoord);

        if ($prevDist < $this->minSegmentDistanceMeters || $nextDist < $this->minSegmentDistanceMeters) {
            return $curr;
        }

        $angle = $this->calculateAngleDegrees($prevCoord, $currCoord, $nextCoord);

        if ($angle === null || $angle < $this->cornerAngleThresholdDeg) {
            return $curr;
        }

        $severity = min(1.0, ($angle - $this->cornerAngleThresholdDeg) / (180.0 - $this->cornerAngleThresholdDeg));
        $factor = $this->cornerSmoothFactor * $severity;

        $midLat = ($prevCoord[0] + $nextCoord[0]) / 2;
        $midLon = ($prevCoord[1] + $nextCoord[1]) / 2;

        $curr->coordinate = [
            $currCoord[0] + $factor * ($midLat - $currCoord[0]),
            $currCoord[1] + $factor * ($midLon - $currCoord[1]),
        ];

        return $curr;
    }

    /**
     * Normalize the coordinate representation on a point to a float array [lat, lon].
     */
    private function normalizePointCoordinate(object $point): array
    {
        $coord = $point->coordinate ?? null;
        $lat = 0.0;
        $lon = 0.0;

        if (is_string($coord)) {
            $decoded = json_decode($coord, true);
            if (is_array($decoded)) {
                $lat = isset($decoded[0]) ? (float) $decoded[0] : 0.0;
                $lon = isset($decoded[1]) ? (float) $decoded[1] : 0.0;
            } else {
                $parts = array_map('floatval', explode(',', $coord));
                $lat = $parts[0] ?? 0.0;
                $lon = $parts[1] ?? 0.0;
            }
        } elseif (is_array($coord)) {
            $lat = isset($coord[0]) ? (float) $coord[0] : 0.0;
            $lon = isset($coord[1]) ? (float) $coord[1] : 0.0;
        } elseif (isset($point->latitude) && isset($point->longitude)) {
            $lat = (float) $point->latitude;
            $lon = (float) $point->longitude;
        }

        $normalized = [$lat, $lon];
        $point->coordinate = $normalized;

        return $normalized;
    }

    /**
     * Compute the angle between two consecutive vectors (prev->curr and curr->next) in degrees.
     */
    private function calculateAngleDegrees(array $prev, array $curr, array $next): ?float
    {
        $v1 = [$curr[0] - $prev[0], $curr[1] - $prev[1]];
        $v2 = [$next[0] - $curr[0], $next[1] - $curr[1]];

        $mag1 = hypot($v1[0], $v1[1]);
        $mag2 = hypot($v2[0], $v2[1]);

        if ($mag1 == 0.0 || $mag2 == 0.0) {
            return null;
        }

        $dot = ($v1[0] * $v2[0]) + ($v1[1] * $v2[1]);
        $cosTheta = $dot / ($mag1 * $mag2);
        $cosTheta = max(-1.0, min(1.0, $cosTheta));

        return rad2deg(acos($cosTheta));
    }

    /**
     * Compute geodesic distance between two coordinates in meters using the haversine formula.
     */
    private function distanceMeters(array $a, array $b): float
    {
        $earthRadius = 6371000; // meters

        $lat1 = deg2rad($a[0]);
        $lat2 = deg2rad($b[0]);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad($b[1] - $a[1]);

        $hav = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        $c = 2 * asin(min(1, sqrt($hav)));

        return $earthRadius * $c;
    }
}


