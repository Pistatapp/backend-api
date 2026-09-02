<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Builds a derived/display trajectory without changing the raw gps_data rows.
 * The algorithm is request-time and linear in the number of rows for normal
 * sampling windows; the rolling window is bounded by time and sample count.
 */
class TractorTrajectoryService
{
    public const MOVING = 'MOVING';
    public const STATIONARY = 'STATIONARY';
    public const TRANSIENT_SPIKE = 'TRANSIENT_SPIKE';
    public const UNCERTAIN = 'UNCERTAIN';
    public const INVALID_COORDINATE = 'INVALID_COORDINATE';

    private const EARTH_RADIUS_METERS = 6371000.0;

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array{rows: array<int,array<string,mixed>>, metrics: array<string,mixed>}
     */
    public function analyze(array $rows, array $profile): array
    {
        if ($rows === []) {
            return ['rows' => [], 'metrics' => $this->emptyMetrics()];
        }

        $points = [];
        foreach ($rows as $index => $row) {
            [$lat, $lon] = $this->parseCoordinate($row['coordinate'] ?? null);
            $points[] = [
                'row' => $row,
                'original_index' => $index,
                'lat' => $lat,
                'lon' => $lon,
                'timestamp' => $this->timestamp($row['date_time'] ?? null),
                'valid' => $this->validCoordinate($lat, $lon),
            ];
        }

        usort($points, static function (array $a, array $b): int {
            $aTime = $a['timestamp']; $bTime = $b['timestamp'];
            if ($aTime === null && $bTime !== null) return 1;
            if ($aTime !== null && $bTime === null) return -1;
            if ($aTime !== $bTime) return ($aTime ?? PHP_INT_MAX) <=> ($bTime ?? PHP_INT_MAX);
            $aBatch = $a['row']['batch_index'] ?? null; $bBatch = $b['row']['batch_index'] ?? null;
            if ($aBatch === null && $bBatch !== null) return 1;
            if ($aBatch !== null && $bBatch === null) return -1;
            if ($aBatch !== $bBatch) return ((int) $aBatch) <=> ((int) $bBatch);
            return ((int) ($a['row']['id'] ?? $a['original_index'])) <=> ((int) ($b['row']['id'] ?? $b['original_index']));
        });

        $n = count($points);
        $classification = array_fill(0, $n, self::UNCERTAIN);
        $segment = array_fill(0, $n, 0);
        $display = array_fill(0, $n, true);
        $distanceFromPrevious = array_fill(0, $n, 0.0);
        $impliedSpeed = array_fill(0, $n, 0.0);

        for ($i = 0; $i < $n; $i++) {
            if (!$points[$i]['valid']) {
                $classification[$i] = self::INVALID_COORDINATE;
                $display[$i] = false;
                continue;
            }
            if ($i === 0 || !$points[$i - 1]['valid'] || $points[$i]['timestamp'] === null || $points[$i - 1]['timestamp'] === null) {
                continue;
            }

            $dt = $points[$i]['timestamp'] - $points[$i - 1]['timestamp'];
            if ($dt <= 0) {
                continue;
            }
            $distanceFromPrevious[$i] = $this->distance($points[$i - 1], $points[$i]);
            $impliedSpeed[$i] = $distanceFromPrevious[$i] / $dt * 3.6;
            if ($dt > (int) $profile['gap_seconds']) {
                $segment[$i] = $segment[$i - 1] + 1;
            } else {
                $segment[$i] = $segment[$i - 1];
            }
            // A range-valid coordinate can still be contextually impossible
            // for a tractor (for example, a jump to another country in a few
            // seconds). Keep the row, but isolate it from the display route.
            if ($impliedSpeed[$i] > $profile['max_plausible_speed_kmh']
                && (float) ($points[$i]['row']['speed'] ?? 0) <= $profile['max_plausible_speed_kmh']) {
                $classification[$i] = self::UNCERTAIN;
                $display[$i] = false;
                $segment[$i] = $segment[$i - 1] + 1;
            }
        }

        // A point is a spike only when both neighboring legs are implausible and
        // the neighbors are close. A large legitimate journey gap is segmented,
        // not silently discarded.
        for ($i = 1; $i < $n - 1; $i++) {
            if (!$points[$i]['valid'] || !$points[$i - 1]['valid'] || !$points[$i + 1]['valid']) {
                continue;
            }
            $prevDt = $this->positiveDelta($points[$i - 1], $points[$i]);
            $nextDt = $this->positiveDelta($points[$i], $points[$i + 1]);
            if ($prevDt === null || $nextDt === null || $prevDt > $profile['gap_seconds'] || $nextDt > $profile['gap_seconds']) {
                continue;
            }
            $prevDistance = $this->distance($points[$i - 1], $points[$i]);
            $nextDistance = $this->distance($points[$i], $points[$i + 1]);
            $neighborDistance = $this->distance($points[$i - 1], $points[$i + 1]);
            $reportedSpeed = (float) ($points[$i]['row']['speed'] ?? 0);
            if (($prevDistance / $prevDt * 3.6) > $profile['max_plausible_speed_kmh']
                && ($nextDistance / $nextDt * 3.6) > $profile['max_plausible_speed_kmh']
                && $neighborDistance <= $profile['noise_radius_meters'] * 2.0
                && $reportedSpeed <= $profile['max_plausible_speed_kmh']) {
                $classification[$i] = self::TRANSIENT_SPIKE;
                $display[$i] = false;
            }
        }

        // Use bounded rolling windows. Coordinate-wise medians avoid a single
        // excursion pulling the stationary center away from observed points.
        $stationary = array_fill(0, $n, false);
        $stationaryWindowSeconds = (int) config('trajectory.stationary.window_seconds', 180);
        $maximumWindowPoints = (int) config('trajectory.stationary.maximum_window_points', 48);
        $minimumWindowSeconds = (int) config('trajectory.stationary.minimum_window_seconds', 60);
        $minimumPoints = (int) config('trajectory.stationary.minimum_points', 3);
        $lowSpeed = (float) config('trajectory.stationary.low_speed_kmh', 2.0);
        $p95Multiplier = (float) config('trajectory.stationary.p95_multiplier', 1.0);

        for ($i = 0; $i < $n; $i++) {
            if (!$points[$i]['valid'] || $classification[$i] === self::TRANSIENT_SPIKE) {
                continue;
            }
            $window = [];
            for ($j = $i; $j >= 0; $j--) {
                if (count($window) >= $maximumWindowPoints) {
                    break;
                }
                if (!$points[$j]['valid'] || $classification[$j] === self::TRANSIENT_SPIKE) {
                    break;
                }
                if ($j < $i && $this->positiveDelta($points[$j], $points[$i]) > $stationaryWindowSeconds) {
                    break;
                }
                if ((float) ($points[$j]['row']['speed'] ?? 0) > $lowSpeed) {
                    break;
                }
                $window[] = $j;
            }
            if (count($window) < $minimumPoints) {
                continue;
            }
            sort($window);
            $first = $points[$window[0]];
            $last = $points[$window[count($window) - 1]];
            $duration = $this->positiveDelta($first, $last) ?? 0;
            if ($duration < $minimumWindowSeconds) {
                continue;
            }
            [$centerLat, $centerLon] = $this->coordinateMedian($window, $points);
            $radii = array_map(fn (int $idx) => $this->distanceTo($centerLat, $centerLon, $points[$idx]), $window);
            sort($radii);
            $p95 = $radii[min(count($radii) - 1, (int) floor((count($radii) - 1) * 0.95))];
            $endpointDistance = $this->distance($first, $last);
            if ($p95 <= $profile['noise_radius_meters'] * $p95Multiplier
                && $endpointDistance <= $profile['noise_radius_meters']) {
                foreach ($window as $idx) {
                    $stationary[$idx] = true;
                }
            }
        }

        for ($i = 0; $i < $n; $i++) {
            if (!$points[$i]['valid']) {
                $classification[$i] = self::INVALID_COORDINATE;
                continue;
            }
            if ($classification[$i] === self::TRANSIENT_SPIKE) {
                continue;
            }
            if ($stationary[$i]) {
                $classification[$i] = self::STATIONARY;
                continue;
            }
            if ($classification[$i] === self::UNCERTAIN && $display[$i] === false) {
                continue;
            }
            if ($this->hasProgressionEvidence($i, $points, $profile)) {
                $classification[$i] = self::MOVING;
            }
        }

        // Collapse each stationary run to one observed representative point.
        $stationaryClusters = 0;
        $stationaryClusterDistance = 0.0;
        for ($i = 0; $i < $n;) {
            if ($classification[$i] !== self::STATIONARY) {
                $i++;
                continue;
            }
            $start = $i;
            while ($i + 1 < $n && $classification[$i + 1] === self::STATIONARY && $segment[$i + 1] === $segment[$start]) {
                $i++;
            }
            $end = $i;
            $cluster = range($start, $end);
            $representative = $this->medoidIndex($cluster, $points);
            foreach ($cluster as $idx) {
                $display[$idx] = $idx === $representative;
            }
            $stationaryClusters++;
            $stationaryClusterDistance += $this->distance($points[$start], $points[$end]);
            $i++;
        }

        $lastSegment = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($i > 0 && !$points[$i]['valid']) {
                $lastSegment = max($lastSegment + 1, $segment[$i]);
            } elseif ($segment[$i] > $lastSegment) {
                $lastSegment = $segment[$i];
            }
            $segment[$i] = max($segment[$i], $lastSegment);
            if ($classification[$i] === self::INVALID_COORDINATE || $classification[$i] === self::TRANSIENT_SPIKE) {
                $display[$i] = false;
            }
        }

        $displayIndexes = array_keys(array_filter($display));
        foreach ($displayIndexes as $displayPosition => $idx) {
            $row = &$points[$idx]['row'];
            $row['trajectory_classification'] = $classification[$idx];
            $row['segment_id'] = $segment[$idx];
            $row['is_display_point'] = true;
            $row['is_stopped'] = $classification[$idx] === self::STATIONARY;
            $row['trajectory_distance_from_previous_meters'] = round($distanceFromPrevious[$idx], 2);
            $row['trajectory_implied_speed_kmh'] = round($impliedSpeed[$idx], 2);
            unset($row);
        }
        for ($i = 0; $i < $n; $i++) {
            if ($display[$i]) {
                continue;
            }
            $row = &$points[$i]['row'];
            $row['trajectory_classification'] = $classification[$i];
            $row['segment_id'] = $segment[$i];
            $row['is_display_point'] = false;
            $row['is_stopped'] = $classification[$i] === self::STATIONARY;
            $row['trajectory_distance_from_previous_meters'] = round($distanceFromPrevious[$i], 2);
            $row['trajectory_implied_speed_kmh'] = round($impliedSpeed[$i], 2);
            unset($row);
        }

        $resultRows = array_map(fn (array $point) => $point['row'], $points);
        foreach ($resultRows as $idx => &$row) {
            $row['is_starting_point'] = $idx === ($displayIndexes[0] ?? -1);
            $row['is_ending_point'] = $idx === ($displayIndexes[count($displayIndexes) - 1] ?? -1);
            if ($row['trajectory_classification'] === self::STATIONARY && $row['is_display_point']) {
                $row['stoppage_time_seconds'] = $this->stationaryDuration($idx, $classification, $points);
            }
        }
        unset($row);

        $metrics = $this->metrics($resultRows, $classification, $segment, $distanceFromPrevious, $display, $stationaryClusters, $stationaryClusterDistance);
        return ['rows' => $resultRows, 'metrics' => $metrics];
    }

    private function hasProgressionEvidence(int $index, array $points, array $profile): bool
    {
        $required = (int) config('trajectory.movement.minimum_progression_points', 3);
        $half = intdiv($required, 2);
        $start = max(0, $index - $half);
        $end = min(count($points) - 1, $index + $half);
        $valid = [];
        for ($i = $start; $i <= $end; $i++) {
            if ($points[$i]['valid'] && ($i === $index || (float) ($points[$i]['row']['speed'] ?? 0) <= config('trajectory.stationary.low_speed_kmh', 2.0))) {
                $valid[] = $i;
            }
        }
        if (count($valid) < $required) {
            return (float) ($points[$index]['row']['speed'] ?? 0) > 0;
        }
        $first = $points[$valid[0]];
        $last = $points[$valid[count($valid) - 1]];
        $net = $this->distance($first, $last);
        $total = 0.0;
        $positiveSteps = 0;
        for ($i = 1; $i < count($valid); $i++) {
            $step = $this->distance($points[$valid[$i - 1]], $points[$valid[$i]]);
            $total += $step;
            if ($step > 0) $positiveSteps++;
        }
        $ratio = $total > 0 ? $net / $total : 0.0;
        return (float) ($points[$index]['row']['speed'] ?? 0) > 0
            || ($net >= $profile['noise_radius_meters'] * (float) config('trajectory.movement.minimum_net_displacement_multiplier', 1.25)
                && $positiveSteps >= $required - 1
                && $ratio >= (float) config('trajectory.movement.minimum_directional_consistency', 0.55));
    }

    private function stationaryDuration(int $index, array $classification, array $points): int
    {
        $start = $index;
        while ($start > 0 && $classification[$start - 1] === self::STATIONARY) $start--;
        $end = $index;
        while ($end + 1 < count($classification) && $classification[$end + 1] === self::STATIONARY) $end++;
        return max(0, ($points[$end]['timestamp'] ?? 0) - ($points[$start]['timestamp'] ?? 0));
    }

    private function metrics(array $rows, array $classification, array $segments, array $distances, array $display, int $clusters, float $clusterDistance): array
    {
        $movementDistance = 0.0;
        foreach ($distances as $i => $distance) {
            $previousClassification = $classification[$i - 1] ?? null;
            $sameSegment = $i === 0 || (($segments[$i] ?? null) === ($segments[$i - 1] ?? null));
            if (($classification[$i] ?? null) === self::MOVING
                && ($display[$i] ?? false)
                && $sameSegment
                && !in_array($previousClassification, [self::TRANSIENT_SPIKE, self::INVALID_COORDINATE, self::UNCERTAIN], true)) {
                $movementDistance += $distance;
            }
        }
        return [
            'raw_point_count' => count($rows),
            'display_point_count' => count(array_filter($display)),
            'stationary_cluster_count' => $clusters,
            'transient_spike_count' => count(array_filter($classification, fn ($v) => $v === self::TRANSIENT_SPIKE)),
            'operational_movement_distance_meters' => round($movementDistance, 2),
            'stationary_cluster_displacement_meters' => round($clusterDistance, 2),
        ];
    }

    private function emptyMetrics(): array
    {
        return ['raw_point_count' => 0, 'display_point_count' => 0, 'stationary_cluster_count' => 0, 'transient_spike_count' => 0, 'operational_movement_distance_meters' => 0.0, 'stationary_cluster_displacement_meters' => 0.0];
    }

    private function medoidIndex(array $indexes, array $points): int
    {
        // A true medoid would require O(k²) pairwise work. The closest
        // observed point to the coordinate-wise median is a robust, observed
        // representative with O(k) work and the same important property here.
        [$lat, $lon] = $this->coordinateMedian($indexes, $points);
        $best = $indexes[0]; $bestDistance = INF;
        foreach ($indexes as $candidate) {
            $distance = $this->distanceTo($lat, $lon, $points[$candidate]);
            if ($distance < $bestDistance) { $best = $candidate; $bestDistance = $distance; }
        }
        return $best;
    }

    private function coordinateMedian(array $indexes, array $points): array
    {
        $lats = array_map(fn ($i) => $points[$i]['lat'], $indexes);
        $lons = array_map(fn ($i) => $points[$i]['lon'], $indexes);
        sort($lats); sort($lons); $middle = intdiv(count($indexes), 2);
        return [$lats[$middle], $lons[$middle]];
    }

    private function positiveDelta(array $a, array $b): ?int
    {
        if ($a['timestamp'] === null || $b['timestamp'] === null) return null;
        $delta = $b['timestamp'] - $a['timestamp'];
        return $delta > 0 ? $delta : null;
    }

    private function timestamp(mixed $value): ?int
    {
        if (!$value) return null;
        try { return Carbon::parse((string) $value, config('app.timezone', 'Asia/Tehran'))->timestamp; } catch (\Throwable) { return null; }
    }

    private function parseCoordinate(mixed $coordinate): array
    {
        if (is_string($coordinate)) {
            if (str_starts_with(trim($coordinate), '[')) $coordinate = json_decode($coordinate, true);
            else $coordinate = explode(',', $coordinate, 2);
        }
        return [(float) ($coordinate[0] ?? NAN), (float) ($coordinate[1] ?? NAN)];
    }

    private function validCoordinate(float $lat, float $lon): bool
    {
        return is_finite($lat) && is_finite($lon) && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180 && !($lat == 0.0 && $lon == 0.0);
    }

    private function distance(array $a, array $b): float { return $this->distanceTo($a['lat'], $a['lon'], $b); }

    private function distanceTo(float $lat, float $lon, array $point): float
    {
        $dLat = deg2rad($point['lat'] - $lat); $dLon = deg2rad($point['lon'] - $lon);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($point['lat'])) * sin($dLon / 2) ** 2;
        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a)));
    }
}
