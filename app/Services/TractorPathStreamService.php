<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for streaming tractor GPS path data with filtering and smoothing.
 *
 * Processing pipeline:
 * 1. Fetch raw GPS data from database
 * 2. Filter GPS jumps (teleportation artifacts)
 * 3. Smooth sharp corners and turnarounds
 * 4. Detect and mark stoppages
 * 5. Format and stream results
 *
 * Optimized for performance with sub-1000ms response times using:
 * - Raw PDO queries (no Eloquent overhead)
 * - Unbuffered queries for true streaming
 * - Generator-based processing (minimal memory usage)
 */
class TractorPathStreamService
{
    // Stoppage detection constants
    private const MIN_STOPPAGE_SECONDS = 60;

    // GPS jump filter constants
    private const JUMP_FILTER_TIME_SECONDS = 10;
    private const JUMP_FILTER_DISTANCE_METERS = 100;

    // Corner smoothing constants
    private const CORNER_ANGLE_THRESHOLD_DEG = 140.0;
    private const TURNAROUND_ANGLE_THRESHOLD_DEG = 60.0;
    private const MIN_SEGMENT_DISTANCE_METERS = 2.0;
    private const SMOOTHING_WINDOW_SIZE = 5;

    // Distance calculation constants
    private const EARTH_RADIUS_METERS = 6371000;

    // Movement detection constants
    private const MOVEMENT_BUFFER_SIZE = 3;

    /**
     * Retrieves the tractor movement path for a specific date using GPS data analysis.
     * Optimized for sub-1000ms response times using raw queries and minimal processing.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Http\StreamedResponse
     */
    public function getTractorPath(Tractor $tractor, Carbon $date)
    {
        try {
            $tractorId = $tractor->id;

            // Use range-based date filter for optimal index utilization
            // This is faster than DATE(date_time) = ? which prevents index usage
            $startOfDay = $date->copy()->startOfDay()->format('Y-m-d H:i:s');
            $endOfDay = $date->copy()->endOfDay()->format('Y-m-d H:i:s');

            // Fast existence check with range-based query (uses composite index)
            $hasData = DB::table('gps_data')
                ->where('tractor_id', $tractorId)
                ->where('date_time', '>=', $startOfDay)
                ->where('date_time', '<=', $endOfDay)
                ->limit(1)
                ->exists();

            if (!$hasData) {
                $lastPoint = $this->getLastPointFromPreviousDateRaw($tractorId, $startOfDay);
                if ($lastPoint) {
                    return response()->streamJson($this->yieldSinglePoint($lastPoint));
                }
                return response()->streamJson(new \EmptyIterator());
            }

            // Stream raw rows without Eloquent model hydration
            // This is significantly faster than cursor() with models
            return response()->streamJson(
                $this->streamPathPointsRaw($tractorId, $startOfDay, $endOfDay)
            );

        } catch (\Exception $e) {
            Log::error('Failed to get tractor path (streamed)', [
                'tractor_id' => $tractor->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);

            return response()->streamJson(new \EmptyIterator());
        }
    }

    /**
     * Stream path points using raw PDO for maximum performance.
     * Bypasses Eloquent hydration entirely.
     *
     * @param int $tractorId
     * @param string $startOfDay
     * @param string $endOfDay
     * @return \Generator
     */
    private function streamPathPointsRaw(int $tractorId, string $startOfDay, string $endOfDay): \Generator
    {
        $pdo = DB::connection()->getPdo();

        // Use unbuffered query for true streaming with minimal memory
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        // Range-based query uses composite index (tractor_id, date_time) efficiently
        $stmt = $pdo->prepare('
            SELECT id, coordinate, speed, status, directions, date_time
            FROM gps_data
            WHERE tractor_id = ?
              AND date_time >= ?
              AND date_time <= ?
            ORDER BY date_time ASC
        ');
        $stmt->execute([$tractorId, $startOfDay, $endOfDay]);

        yield from $this->buildPathFromRawStream($stmt);

        // Restore buffered query mode
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    /**
     * Yield a single point formatted for response.
     *
     * @param object $point
     * @return \Generator
     */
    private function yieldSinglePoint(object $point): \Generator
    {
        yield $this->formatRawPoint($point, false, false, false, 0);
    }

    /**
     * Get the last point from previous date using raw query.
     *
     * @param int $tractorId
     * @param string $startOfDay Start of the current day (used as upper bound)
     * @return object|null
     */
    private function getLastPointFromPreviousDateRaw(int $tractorId, string $startOfDay): ?object
    {
        // Use range query for index optimization
        return DB::table('gps_data')
            ->select(['id', 'coordinate', 'speed', 'status', 'directions', 'date_time'])
            ->where('tractor_id', $tractorId)
            ->where('date_time', '<', $startOfDay)
            ->orderByDesc('date_time')
            ->limit(1)
            ->first();
    }

    /**
     * Format a raw database row to response array.
     * Optimized: minimal parsing, no Carbon overhead for simple time formatting.
     *
     * @param object $row Raw database row
     * @param bool $isStartingPoint
     * @param bool $isEndingPoint
     * @param bool $isStopped
     * @param int $stoppageTime Seconds
     * @return array
     */
    private function formatRawPoint(
        object $row,
        bool $isStartingPoint,
        bool $isEndingPoint,
        bool $isStopped,
        int $stoppageTime
    ): array {
        $coord = $this->parseCoordinate($row->coordinate);
        $directions = $this->parseJsonField($row->directions);
        $timestamp = $this->extractTimeFromDateTime($row->date_time);

        return [
            'id' => (int) $row->id,
            'latitude' => (float) ($coord[0] ?? 0),
            'longitude' => (float) ($coord[1] ?? 0),
            'speed' => (int) $row->speed,
            'status' => (int) $row->status,
            'is_starting_point' => $isStartingPoint,
            'is_ending_point' => $isEndingPoint,
            'is_stopped' => $isStopped,
            'directions' => $directions,
            'stoppage_time' => to_time_format($stoppageTime),
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Parse JSON field from database (handles both JSON string and already decoded value).
     *
     * @param mixed $field
     * @return mixed
     */
    private function parseJsonField($field)
    {
        if (is_string($field)) {
            return json_decode($field, true);
        }
        return $field;
    }

    /**
     * Extract time portion from datetime string (HH:MM:SS).
     * Format: "YYYY-MM-DD HH:MM:SS" -> "HH:MM:SS"
     *
     * @param string|null $dateTime
     * @return string
     */
    private function extractTimeFromDateTime(?string $dateTime): string
    {
        if (is_string($dateTime) && strlen($dateTime) >= 19) {
            return substr($dateTime, 11, 8);
        }
        return '00:00:00';
    }

    /**
     * Build path from raw PDO statement stream.
     * Optimized: processes raw rows directly without model hydration.
     * Applies a multi-stage pipeline:
     *   1. Jump detection - filters GPS teleportation artifacts
     *   2. Corner smoothing - smooths sharp corners and turnarounds
     *   3. Stoppage detection - identifies stopped points
     *
     * @param \PDOStatement $stmt
     * @return \Generator
     */
    private function buildPathFromRawStream(\PDOStatement $stmt): \Generator
    {
        // Pipeline: Raw -> Filter Jumps -> Smooth Corners -> Process Stoppages
        yield from $this->processCleanedStream(
            $this->smoothCornersAndTurnarounds(
                $this->filterJumps($this->fetchRowsAsGenerator($stmt))
            )
        );
    }

    /**
     * Convert PDOStatement to a generator for composability.
     *
     * @param \PDOStatement $stmt
     * @return \Generator
     */
    private function fetchRowsAsGenerator(\PDOStatement $stmt): \Generator
    {
        while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
            yield $row;
        }
    }

    /**
     * Filter out sudden GPS jumps using distance and time thresholds.
     *
     * Algorithm:
     * Remove points where:
     * - Time difference from previous point < 10 seconds AND
     * - Distance from previous point > 100 meters
     *
     * This filters out GPS glitches and teleportation artifacts.
     *
     * @param \Generator $points Raw GPS points generator
     * @return \Generator Filtered points with jumps removed
     */
    private function filterJumps(\Generator $points): \Generator
    {
        $previousPoint = null;

        foreach ($points as $row) {
            $currentCoord = $this->extractCoordinate($row);
            $currentTimestamp = $this->parseTimestampFast($row->date_time);

            // First point is always valid
            if ($previousPoint === null) {
                $previousPoint = [
                    'coord' => $currentCoord,
                    'timestamp' => $currentTimestamp,
                ];
                yield $row;
                continue;
            }

            // Calculate time difference in seconds
            $timeDelta = abs($currentTimestamp - $previousPoint['timestamp']);

            // Calculate distance in meters using helper function
            $distance = calculate_distance(
                $previousPoint['coord'],
                $currentCoord,
                'm'
            );

            // Filter condition: remove if time < threshold AND distance > threshold
            $shouldFilter = ($timeDelta < self::JUMP_FILTER_TIME_SECONDS && $distance > self::JUMP_FILTER_DISTANCE_METERS);

            if ($shouldFilter) {
                // Skip this point (GPS jump detected)
                continue;
            }

            // Valid point - update previous and yield
            $previousPoint = [
                'coord' => $currentCoord,
                'timestamp' => $currentTimestamp,
            ];

            yield $row;
        }
    }


    /**
     * Smooth corners and turnarounds in the GPS path stream.
     *
     * Algorithm:
     * 1. Maintain a sliding window of points.
     * 2. For each center point, calculate the angle formed with neighbors.
     * 3. If the angle indicates a sharp corner (< CORNER_ANGLE_THRESHOLD_DEG),
     *    apply weighted averaging to smooth the coordinate.
     * 4. For turnarounds (< TURNAROUND_ANGLE_THRESHOLD_DEG), apply stronger smoothing.
     * 5. Straight segments pass through unchanged.
     *
     * @param \Generator $points Filtered GPS points
     * @return \Generator Smoothed points
     */
    private function smoothCornersAndTurnarounds(\Generator $points): \Generator
    {
        $window = [];
        $windowSize = self::SMOOTHING_WINDOW_SIZE;
        $centerIndex = (int) floor($windowSize / 2);

        foreach ($points as $row) {
            // Ensure coordinate is normalized as array
            $this->normalizeRowCoordinate($row);
            $window[] = $row;

            // Wait until we have a full window
            if (count($window) < $windowSize) {
                continue;
            }

            // Process the center point
            $smoothedRow = $this->processCornerPoint($window, $centerIndex);
            yield $smoothedRow;

            // Slide window forward
            array_shift($window);
        }

        // Flush remaining points in window without smoothing (edge points)
        foreach ($window as $remainingRow) {
            yield $remainingRow;
        }
    }

    /**
     * Process a single point in the window for potential corner smoothing.
     *
     * @param array $window Array of point objects
     * @param int $centerIndex Index of the center point
     * @return object The (possibly smoothed) center point
     */
    private function processCornerPoint(array $window, int $centerIndex): object
    {
        $prev = $window[$centerIndex - 1] ?? null;
        $curr = $window[$centerIndex];
        $next = $window[$centerIndex + 1] ?? null;

        if (!$prev || !$next) {
            return $curr;
        }

        $prevCoord = $this->extractCoordinate($prev);
        $currCoord = $this->extractCoordinate($curr);
        $nextCoord = $this->extractCoordinate($next);

        if (!$this->hasValidSegmentLengths($prevCoord, $currCoord, $nextCoord)) {
            return $curr;
        }

        $angle = $this->calculateAngleDegrees($prevCoord, $currCoord, $nextCoord);

        if ($angle === null || $angle >= self::CORNER_ANGLE_THRESHOLD_DEG) {
            return $curr;
        }

        $isTurnaround = $angle < self::TURNAROUND_ANGLE_THRESHOLD_DEG;
        return $this->applySmoothingToPoint($window, $centerIndex, $isTurnaround);
    }

    /**
     * Check if segments have valid minimum lengths for corner detection.
     *
     * @param array $prevCoord
     * @param array $currCoord
     * @param array $nextCoord
     * @return bool
     */
    private function hasValidSegmentLengths(array $prevCoord, array $currCoord, array $nextCoord): bool
    {
        $prevDist = $this->haversineDistance($prevCoord, $currCoord);
        $nextDist = $this->haversineDistance($currCoord, $nextCoord);

        return $prevDist >= self::MIN_SEGMENT_DISTANCE_METERS
            && $nextDist >= self::MIN_SEGMENT_DISTANCE_METERS;
    }

    /**
     * Apply weighted averaging to smooth a corner point.
     *
     * For regular corners: use simple average of the window.
     * For turnarounds: use weighted average with stronger central weight reduction.
     *
     * @param array $window Array of point objects
     * @param int $centerIndex Index of the center point
     * @param bool $isTurnaround Whether this is a sharp turnaround
     * @return object The smoothed point
     */
    private function applySmoothingToPoint(array $window, int $centerIndex, bool $isTurnaround): object
    {
        $center = $window[$centerIndex];

        if ($isTurnaround) {
            // For turnarounds, use weighted smoothing that favors neighbors
            // This pulls the sharp turn point toward a more gradual curve
            $weights = $this->getTurnaroundWeights(count($window), $centerIndex);
        } else {
            // For regular corners, use uniform averaging
            $weights = array_fill(0, count($window), 1.0 / count($window));
        }

        $sumLat = 0.0;
        $sumLon = 0.0;
        $totalWeight = 0.0;

        foreach ($window as $i => $point) {
            $coord = $this->extractCoordinate($point);
            $weight = $weights[$i] ?? 0;
            $sumLat += $coord[0] * $weight;
            $sumLon += $coord[1] * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight > 0) {
            $smoothedLat = $sumLat / $totalWeight;
            $smoothedLon = $sumLon / $totalWeight;

            // Update the coordinate on the center point
            $center->coordinate = [$smoothedLat, $smoothedLon];
        }

        return $center;
    }

    /**
     * Generate weights for turnaround smoothing.
     * Uses inverse distance weighting: center point gets reduced weight,
     * neighbors get progressively less weight as distance increases.
     *
     * @param int $windowSize
     * @param int $centerIndex
     * @return array Normalized weights for each position
     */
    private function getTurnaroundWeights(int $windowSize, int $centerIndex): array
    {
        $weights = [];
        $centerWeight = 0.5;

        for ($i = 0; $i < $windowSize; $i++) {
            $distFromCenter = abs($i - $centerIndex);
            $weights[$i] = $distFromCenter === 0 ? $centerWeight : 1.0 / $distFromCenter;
        }

        return $this->normalizeWeights($weights);
    }

    /**
     * Normalize weights array so they sum to 1.0.
     *
     * @param array $weights
     * @return array
     */
    private function normalizeWeights(array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum > 0) {
            foreach ($weights as &$weight) {
                $weight /= $sum;
            }
        }
        return $weights;
    }

    /**
     * Calculate the angle at point B formed by vectors BA and BC.
     *
     * Using the dot product formula:
     *   angle = acos(dot(BA, BC) / (|BA| * |BC|))
     *
     * Result interpretation:
     *   - 180° = straight line (no turn)
     *   - 90° = right angle turn
     *   - 0° = complete reversal (turnaround)
     *
     * @param array $a Previous point [lat, lon]
     * @param array $b Center point [lat, lon]
     * @param array $c Next point [lat, lon]
     * @return float|null Angle in degrees, or null if undefined
     */
    private function calculateAngleDegrees(array $a, array $b, array $c): ?float
    {
        // Vectors BA and BC (from B to A, and from B to C)
        $ba = [$a[0] - $b[0], $a[1] - $b[1]];
        $bc = [$c[0] - $b[0], $c[1] - $b[1]];

        $magBA = hypot($ba[0], $ba[1]);
        $magBC = hypot($bc[0], $bc[1]);

        // Avoid division by zero
        if ($magBA == 0.0 || $magBC == 0.0) {
            return null;
        }

        // Dot product
        $dot = ($ba[0] * $bc[0]) + ($ba[1] * $bc[1]);

        // Cosine of angle
        $cosTheta = $dot / ($magBA * $magBC);

        // Clamp to valid range for acos
        $cosTheta = max(-1.0, min(1.0, $cosTheta));

        return rad2deg(acos($cosTheta));
    }

    /**
     * Parse coordinate from database row (handles both JSON string and array).
     *
     * @param mixed $coordinate Raw coordinate value from database
     * @return array [lat, lon]
     */
    private function parseCoordinate($coordinate): array
    {
        if (is_string($coordinate)) {
            $decoded = json_decode($coordinate, true);
            if (is_array($decoded)) {
                return [(float)($decoded[0] ?? 0), (float)($decoded[1] ?? 0)];
            }
        } elseif (is_array($coordinate)) {
            return [(float)($coordinate[0] ?? 0), (float)($coordinate[1] ?? 0)];
        }

        return [0.0, 0.0];
    }

    /**
     * Extract coordinate array from a raw database row.
     *
     * @param object $row
     * @return array [lat, lon]
     */
    private function extractCoordinate(object $row): array
    {
        return $this->parseCoordinate($row->coordinate);
    }

    /**
     * Normalize the coordinate property on a row object to an array [lat, lon].
     *
     * @param object $row
     * @return void
     */
    private function normalizeRowCoordinate(object $row): void
    {
        if (!isset($row->coordinate) || is_array($row->coordinate)) {
            return;
        }

        $row->coordinate = $this->parseCoordinate($row->coordinate);
    }

    /**
     * Calculate the haversine distance between two coordinates in meters.
     *
     * @param array $coord1 [lat, lon]
     * @param array $coord2 [lat, lon]
     * @return float Distance in meters
     */
    private function haversineDistance(array $coord1, array $coord2): float
    {
        $lat1 = deg2rad($coord1[0]);
        $lat2 = deg2rad($coord2[0]);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad($coord2[1] - $coord1[1]);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        $c = 2 * asin(min(1.0, sqrt($a)));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Process the cleaned (jump-filtered) stream with stoppage detection.
     *
     * @param \Generator $cleanedPoints
     * @return \Generator
     */
    private function processCleanedStream(\Generator $cleanedPoints): \Generator
    {
        $state = $this->initializeStoppageState();

        foreach ($cleanedPoints as $row) {
            $pointInfo = $this->analyzePoint($row, $state);
            $timestamp = $this->parseTimestampFast($row->date_time);

            $this->updateStoppageDuration($state, $pointInfo, $timestamp);

            if ($pointInfo['isMovement']) {
                yield from $this->processMovementPoint($row, $state);
            } elseif ($pointInfo['isStoppage']) {
                yield from $this->processStoppagePoint($row, $state, $pointInfo);
            }

            $state['prevTimestamp'] = $timestamp;
            $state['firstPointProcessed'] = true;
        }

        yield from $this->finalizeStream($state);
    }

    /**
     * Initialize state for stoppage detection.
     *
     * @return array
     */
    private function initializeStoppageState(): array
    {
        return [
            'hasSeenMovement' => false,
            'lastPointType' => null,
            'inStoppageSegment' => false,
            'stoppageDuration' => 0,
            'deferredStoppageRow' => null,
            'stoppageStartedAtFirstPoint' => false,
            'movementBuffer' => [],
            'startingPointAssigned' => false,
            'firstPointProcessed' => false,
            'prevTimestamp' => null,
        ];
    }

    /**
     * Analyze a point to determine its type and characteristics.
     *
     * @param object $row
     * @param array $state
     * @return array
     */
    private function analyzePoint(object $row, array $state): array
    {
        $speed = (int) $row->speed;
        $status = (int) $row->status;

        return [
            'isMovement' => ($status === 1 && $speed > 0),
            'isStoppage' => ($speed === 0),
            'isFirstPoint' => !$state['firstPointProcessed'],
        ];
    }

    /**
     * Update stoppage duration based on current point.
     *
     * @param array $state
     * @param array $pointInfo
     * @param int $timestamp
     * @return void
     */
    private function updateStoppageDuration(array &$state, array $pointInfo, int $timestamp): void
    {
        if ($state['inStoppageSegment'] && $state['prevTimestamp'] !== null && $pointInfo['isStoppage']) {
            $state['stoppageDuration'] += max(0, $timestamp - $state['prevTimestamp']);
        }
    }

    /**
     * Process a movement point.
     *
     * @param object $row
     * @param array $state
     * @return \Generator
     */
    private function processMovementPoint(object $row, array &$state): \Generator
    {
        $state['hasSeenMovement'] = true;

        // Finalize deferred stoppage if exists
        if ($state['inStoppageSegment'] && $state['deferredStoppageRow'] !== null) {
            if ($this->shouldYieldStoppage($state)) {
                yield $this->formatRawPoint($state['deferredStoppageRow'], false, false, true, $state['stoppageDuration']);
            }
            $state['deferredStoppageRow'] = null;
        }

        $this->resetStoppageState($state);

        // Buffer for starting point detection
        $state['movementBuffer'][] = $row;

        yield from $this->processMovementBuffer($state);
        $state['lastPointType'] = 'movement';
    }

    /**
     * Process a stoppage point.
     *
     * @param object $row
     * @param array $state
     * @param array $pointInfo
     * @return \Generator
     */
    private function processStoppagePoint(object $row, array &$state, array $pointInfo): \Generator
    {
        // Flush movement buffer on stoppage
        yield from $this->flushMovementBuffer($state);

        if ($pointInfo['isFirstPoint']) {
            $this->startStoppageSegment($row, $state, true);
        } elseif ($state['hasSeenMovement'] && $state['lastPointType'] !== 'stoppage') {
            $this->startStoppageSegment($row, $state, false);
        }

        $state['lastPointType'] = 'stoppage';
    }

    /**
     * Process movement buffer and yield points when buffer is full.
     *
     * @param array $state
     * @return \Generator
     */
    private function processMovementBuffer(array &$state): \Generator
    {
        $bufferSize = count($state['movementBuffer']);

        if ($bufferSize === self::MOVEMENT_BUFFER_SIZE) {
            $firstRow = array_shift($state['movementBuffer']);
            $isStart = !$state['startingPointAssigned'];
            if ($isStart) {
                $state['startingPointAssigned'] = true;
            }
            yield $this->formatRawPoint($firstRow, $isStart, false, false, 0);
        } elseif ($bufferSize > self::MOVEMENT_BUFFER_SIZE) {
            yield $this->formatRawPoint(array_shift($state['movementBuffer']), false, false, false, 0);
        }
    }

    /**
     * Flush all remaining points in movement buffer.
     *
     * @param array $state
     * @return \Generator
     */
    private function flushMovementBuffer(array &$state): \Generator
    {
        foreach ($state['movementBuffer'] as $bufferedRow) {
            yield $this->formatRawPoint($bufferedRow, false, false, false, 0);
        }
        $state['movementBuffer'] = [];
    }

    /**
     * Start a new stoppage segment.
     *
     * @param object $row
     * @param array $state
     * @param bool $isFirstPoint
     * @return void
     */
    private function startStoppageSegment(object $row, array &$state, bool $isFirstPoint): void
    {
        $state['deferredStoppageRow'] = $row;
        $state['inStoppageSegment'] = true;
        $state['stoppageDuration'] = 0;
        $state['stoppageStartedAtFirstPoint'] = $isFirstPoint;
    }

    /**
     * Reset stoppage state after movement resumes.
     *
     * @param array $state
     * @return void
     */
    private function resetStoppageState(array &$state): void
    {
        $state['inStoppageSegment'] = false;
        $state['stoppageDuration'] = 0;
        $state['stoppageStartedAtFirstPoint'] = false;
    }

    /**
     * Determine if a stoppage should be yielded.
     *
     * @param array $state
     * @return bool
     */
    private function shouldYieldStoppage(array $state): bool
    {
        return $state['stoppageDuration'] >= self::MIN_STOPPAGE_SECONDS || $state['stoppageStartedAtFirstPoint'];
    }

    /**
     * Finalize stream by flushing buffers and deferred stoppages.
     *
     * @param array $state
     * @return \Generator
     */
    private function finalizeStream(array $state): \Generator
    {
        // Flush remaining movement buffer
        yield from $this->flushMovementBuffer($state);

        // Finalize trailing stoppage
        if ($state['inStoppageSegment'] && $state['deferredStoppageRow'] !== null) {
            if ($this->shouldYieldStoppage($state)) {
                yield $this->formatRawPoint($state['deferredStoppageRow'], false, false, true, $state['stoppageDuration']);
            }
        }
    }

    /**
     * Parse Unix timestamp from datetime string without Carbon overhead.
     * Expects format: "YYYY-MM-DD HH:MM:SS"
     *
     * @param string|null $dateTime
     * @return int Unix timestamp
     */
    private function parseTimestampFast(?string $dateTime): int
    {
        if (!$dateTime || strlen($dateTime) < 19) {
            return time();
        }

        // Use strtotime which is faster than Carbon for simple parsing
        return strtotime($dateTime) ?: time();
    }
}


