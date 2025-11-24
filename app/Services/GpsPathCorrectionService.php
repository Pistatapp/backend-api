<?php

namespace App\Services;

/**
 * GPS path-correction algorithms focused on geometric smoothing.
 *
 * This service exposes a streaming algorithm that selectively smooths only sharp
 * corners and turn-arounds without disturbing straight segments. It keeps memory
 * usage O(1) by buffering a small, local window of points at a time.
 */
class GpsPathCorrectionService
{
    /**
     * Corner detection threshold in degrees.
     *
     * Angle is computed at point B for triplet (A, B, C) using vectors AB and CB:
     *   angle = acos( dot(AB, CB) / (|AB| * |CB|) )
     *
     * With this convention:
     *   - Straight segments yield angles near 180°
     *   - Tight corners / turnarounds yield smaller angles (e.g. 0–140°)
     *
     * We treat points with angle < threshold as candidates for smoothing.
     */
    private float $cornerAngleThresholdDeg = 150.0;

    /**
     * Ignore extremely short segments (meters) to avoid amplifying GPS noise.
     */
    private float $minSegmentDistanceMeters = 0.5;

    /**
     * Number of interpolated points to add before and after a smoothed corner.
     */
    private int $numInterpolationPoints = 2;

    /**
     * Stream-based corner smoothing.
     *
     * This implements the "corner-only smoothing" algorithm:
     *  - Maintain a sliding window of up to 5 points.
     *  - For each center point B with neighbors A and C, compute the angle at B.
     *  - If angle(B) < threshold and adjacent segments are not too short, replace B's
     *    coordinate with the average of the local window (i-2 … i+2).
     *  - All other points are yielded unchanged.
     *
     * Edges (first/last few points) are not smoothed.
     *
     * @param \Traversable $points
     * @return \Generator
     */
    public function smoothCornersStream($points): \Generator
    {
        $window = [];

        foreach ($points as $point) {
            $this->normalizePointCoordinate($point);
            $window[] = $point;

            // Build up the initial window; we start smoothing once we have 5 points.
            if (count($window) < 5) {
                continue;
            }

            // Center index for 5-point window [i-2, i-1, i, i+1, i+2]
            $centerIndex = 2;
            $processedPoints = $this->processCorner($window, $centerIndex);

            // Yield the (possibly) smoothed center point(s).
            foreach ($processedPoints as $p) {
                yield $p;
            }

            // Slide window forward by one point.
            array_shift($window);
        }

        // Flush remaining points at the tail without further smoothing.
        foreach ($window as $remaining) {
            yield $remaining;
        }
    }

    /**
     * Apply selective smoothing to the center of a 5-point window if it represents a corner/turnaround.
     * Returns an array of points (including interpolated ones if smoothed).
     */
    private function processCorner(array $window, int $centerIndex): array
    {
        // Defensive: require at least 3 points and a valid center index.
        if (count($window) < 3 || !isset($window[$centerIndex])) {
            return [$window[$centerIndex] ?? reset($window)];
        }

        // Use immediate neighbors for angle computation: A = i-1, B = i, C = i+1
        $prev = $window[$centerIndex - 1] ?? null;
        $curr = $window[$centerIndex];
        $next = $window[$centerIndex + 1] ?? null;

        if (!$prev || !$next) {
            return [$curr];
        }

        $prevCoord = $this->normalizePointCoordinate($prev);
        $currCoord = $this->normalizePointCoordinate($curr);
        $nextCoord = $this->normalizePointCoordinate($next);

        $prevDist = $this->distanceMeters($prevCoord, $currCoord);
        $nextDist = $this->distanceMeters($currCoord, $nextCoord);

        if ($prevDist < $this->minSegmentDistanceMeters || $nextDist < $this->minSegmentDistanceMeters) {
            return [$curr];
        }

        $angle = $this->calculateAngleDegrees($prevCoord, $currCoord, $nextCoord);

        // If angle is invalid or not a "corner", return original center.
        if ($angle === null || $angle >= $this->cornerAngleThresholdDeg) {
            return [$curr];
        }

        // Angle below threshold → we treat this as a corner and smooth locally.
        // Use a 5-point moving average over the available window (i-2 … i+2).
        $sumLat = 0.0;
        $sumLon = 0.0;
        $count = 0;

        foreach ($window as $pt) {
            $coord = $this->normalizePointCoordinate($pt);
            $sumLat += $coord[0];
            $sumLon += $coord[1];
            $count++;
        }

        if ($count > 0) {
            $avgLat = $sumLat / $count;
            $avgLon = $sumLon / $count;
            $curr->coordinate = [$avgLat, $avgLon];

            // Also update lat/lon properties if they exist
            if (isset($curr->latitude)) {
                $curr->latitude = $avgLat;
            }
            if (isset($curr->longitude)) {
                $curr->longitude = $avgLon;
            }
        }

        // Generate interpolated points using the NEW smoothed coordinate of curr.
        // We interpolate between prev -> curr and curr -> next.
        $before = $this->interpolatePoints($prev, $curr, $this->numInterpolationPoints);
        $after  = $this->interpolatePoints($curr, $next, $this->numInterpolationPoints);

        return array_merge($before, [$curr], $after);
    }

    /**
     * Interpolate points linearly between two points.
     */
    private function interpolatePoints(object $start, object $end, int $count): array
    {
        $startCoord = $this->normalizePointCoordinate($start);
        $endCoord = $this->normalizePointCoordinate($end);

        $newPoints = [];

        for ($i = 1; $i <= $count; $i++) {
            $fraction = $i / ($count + 1);
            $lat = $startCoord[0] + ($endCoord[0] - $startCoord[0]) * $fraction;
            $lon = $startCoord[1] + ($endCoord[1] - $startCoord[1]) * $fraction;

            $newPoint = clone $start;
            // Clear ID if it exists to avoid conflicts
            if (isset($newPoint->id)) {
                unset($newPoint->id);
            }

            $newPoint->coordinate = [$lat, $lon];
            if (isset($newPoint->latitude)) {
                $newPoint->latitude = $lat;
            }
            if (isset($newPoint->longitude)) {
                $newPoint->longitude = $lon;
            }

            $newPoints[] = $newPoint;
        }

        return $newPoints;
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
        // Vectors AB (prev->curr) and CB (next->curr) to match the described algorithm.
        $v1 = [$curr[0] - $prev[0], $curr[1] - $prev[1]]; // AB
        $v2 = [$curr[0] - $next[0], $curr[1] - $next[1]]; // CB

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


