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
 * 2. Detect and interpolate GPS noise/jumps (replaces artifacts with realistic values)
 * 3. Smooth sharp corners and turnarounds
 * 4. Detect and mark stoppages
 * 5. Format and stream results
 *
 * Noise Detection & Interpolation:
 * - Detects unrealistic speeds (>30 m/s)
 * - Detects teleportation jumps (>100m in <10s)
 * - Detects trajectory deviations ("spike" noise)
 * - Replaces noisy points with linearly interpolated coordinates
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

    // GPS noise/jump detection constants
    private const JUMP_FILTER_TIME_SECONDS = 10;
    private const JUMP_FILTER_DISTANCE_METERS = 100;

    // Noise detection and interpolation constants
    private const NOISE_DETECTION_WINDOW = 5;           // Points to look ahead for noise detection
    private const MAX_REALISTIC_SPEED_MPS = 30.0;       // Max realistic tractor speed (30 m/s ≈ 108 km/h)
    private const MIN_NOISE_DISTANCE_METERS = 20.0;     // Minimum distance to consider as potential noise
    private const NOISE_ANGLE_THRESHOLD_DEG = 45.0;     // Angle deviation threshold for noise detection
    private const CONSECUTIVE_NOISE_LIMIT = 3;          // Max consecutive noisy points to interpolate

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
     *   1. Noise/jump interpolation - replaces GPS artifacts with interpolated values
     *   2. Corner smoothing - smooths sharp corners and turnarounds
     *   3. Stoppage detection - identifies stopped points
     *
     * @param \PDOStatement $stmt
     * @return \Generator
     */
    private function buildPathFromRawStream(\PDOStatement $stmt): \Generator
    {
        // Pipeline: Raw -> Interpolate Noise -> Smooth Corners -> Process Stoppages
        yield from $this->processCleanedStream(
            $this->smoothCornersAndTurnarounds(
                $this->interpolateNoiseAndJumps($this->fetchRowsAsGenerator($stmt))
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
     * Detect and interpolate GPS noise and sudden jumps.
     *
     * Instead of removing noisy points, this algorithm replaces them with
     * interpolated coordinates that create a realistic path between valid points.
     *
     * Algorithm:
     * 1. Buffer points in a sliding window
     * 2. Detect noise using multiple criteria:
     *    - Unrealistic speed (distance/time exceeds max tractor speed)
     *    - Sudden direction changes that don't match trajectory
     *    - Points that "jump back" after teleporting
     * 3. When noise is detected, interpolate coordinates between last valid
     *    point and next valid point
     * 4. Preserve original timestamps and other metadata
     *
     * @param \Generator $points Raw GPS points generator
     * @return \Generator Points with noise replaced by interpolated values
     */
    private function interpolateNoiseAndJumps(\Generator $points): \Generator
    {
        $buffer = [];
        $lastValidPoint = null;
        $windowSize = self::NOISE_DETECTION_WINDOW;
        $bufferFilled = false;

        // Single loop: fill buffer first, then process with look-ahead
        foreach ($points as $row) {
            $this->normalizeRowCoordinate($row);
            $buffer[] = $this->enrichPointWithMetadata($row);

            // Still filling the initial buffer
            if (!$bufferFilled) {
                if (count($buffer) >= $windowSize) {
                    $bufferFilled = true;
                }
                continue;
            }

            // Buffer is full - process and yield the oldest point
            $processedPoint = $this->processBufferedPoint($buffer, $lastValidPoint);

            if ($processedPoint !== null) {
                if (!$processedPoint['_is_interpolated']) {
                    $lastValidPoint = $processedPoint;
                }
                yield $processedPoint['_row'];
            }

            array_shift($buffer);
        }

        // Flush remaining buffer
        while (!empty($buffer)) {
            $processedPoint = $this->processBufferedPoint($buffer, $lastValidPoint);

            if ($processedPoint !== null) {
                if (!$processedPoint['_is_interpolated']) {
                    $lastValidPoint = $processedPoint;
                }
                yield $processedPoint['_row'];
            }

            array_shift($buffer);
        }
    }

    /**
     * Enrich a point with pre-computed metadata for noise detection.
     *
     * @param object $row
     * @return array
     */
    private function enrichPointWithMetadata(object $row): array
    {
        $coord = $this->extractCoordinate($row);
        $timestamp = $this->parseTimestampFast($row->date_time);

        return [
            '_row' => $row,
            '_coord' => $coord,
            '_timestamp' => $timestamp,
            '_is_interpolated' => false,
        ];
    }

    /**
     * Process a buffered point: detect if it's noise and interpolate if needed.
     *
     * @param array $buffer Current buffer of enriched points
     * @param array|null $lastValidPoint Last known valid point
     * @return array|null Processed point with metadata, or null if buffer empty
     */
    private function processBufferedPoint(array &$buffer, ?array $lastValidPoint): ?array
    {
        if (empty($buffer)) {
            return null;
        }

        $currentPoint = $buffer[0];

        // First point is always valid if no previous reference
        if ($lastValidPoint === null) {
            return $currentPoint;
        }

        // Check if current point is noise
        $noiseInfo = $this->detectNoise($currentPoint, $lastValidPoint, $buffer);

        if (!$noiseInfo['isNoise']) {
            return $currentPoint;
        }

        // Find next valid point for interpolation
        $nextValidPoint = $this->findNextValidPoint($buffer, $lastValidPoint, 1);

        if ($nextValidPoint === null) {
            // No valid point found ahead - use last valid point's coordinate
            // (effectively "holding" position during noise)
            return $this->createInterpolatedPoint(
                $currentPoint,
                $lastValidPoint['_coord'],
                $lastValidPoint['_coord'],
                0.0
            );
        }

        // Interpolate between last valid and next valid
        $interpolatedPoint = $this->interpolatePoint(
            $currentPoint,
            $lastValidPoint,
            $nextValidPoint
        );

        return $interpolatedPoint;
    }

    /**
     * Detect if a point is GPS noise based on multiple criteria.
     *
     * Criteria:
     * 1. Speed check: distance/time exceeds realistic max speed
     * 2. Jump detection: large distance in short time
     * 3. Trajectory deviation: point deviates significantly from expected path
     *
     * @param array $currentPoint
     * @param array $lastValidPoint
     * @param array $buffer Look-ahead buffer
     * @return array ['isNoise' => bool, 'reason' => string]
     */
    private function detectNoise(array $currentPoint, array $lastValidPoint, array $buffer): array
    {
        $distance = $this->haversineDistance($lastValidPoint['_coord'], $currentPoint['_coord']);
        $timeDelta = max(1, abs($currentPoint['_timestamp'] - $lastValidPoint['_timestamp']));
        $speed = $distance / $timeDelta; // meters per second

        // Criterion 1: Unrealistic speed
        if ($speed > self::MAX_REALISTIC_SPEED_MPS && $distance > self::MIN_NOISE_DISTANCE_METERS) {
            return ['isNoise' => true, 'reason' => 'unrealistic_speed'];
        }

        // Criterion 2: Classic jump detection (short time, large distance)
        if ($timeDelta < self::JUMP_FILTER_TIME_SECONDS && $distance > self::JUMP_FILTER_DISTANCE_METERS) {
            return ['isNoise' => true, 'reason' => 'teleportation_jump'];
        }

        // Criterion 3: Trajectory deviation (requires look-ahead)
        if (count($buffer) >= 3) {
            $trajectoryNoise = $this->detectTrajectoryDeviation($currentPoint, $lastValidPoint, $buffer);
            if ($trajectoryNoise) {
                return ['isNoise' => true, 'reason' => 'trajectory_deviation'];
            }
        }

        return ['isNoise' => false, 'reason' => ''];
    }

    /**
     * Detect if point deviates from expected trajectory.
     *
     * A point is considered trajectory noise if:
     * - It creates a sharp angle with the previous trajectory
     * - The next point returns closer to the original trajectory
     *
     * This catches "spike" noise where GPS briefly jumps away and returns.
     *
     * @param array $currentPoint
     * @param array $lastValidPoint
     * @param array $buffer
     * @return bool
     */
    private function detectTrajectoryDeviation(array $currentPoint, array $lastValidPoint, array $buffer): bool
    {
        // Need at least one point ahead
        if (count($buffer) < 2) {
            return false;
        }

        $nextPoint = $buffer[1];

        // Calculate distances
        $distToCurrent = $this->haversineDistance($lastValidPoint['_coord'], $currentPoint['_coord']);
        $distCurrentToNext = $this->haversineDistance($currentPoint['_coord'], $nextPoint['_coord']);
        $distDirectToNext = $this->haversineDistance($lastValidPoint['_coord'], $nextPoint['_coord']);

        // Skip if distances are too small for reliable angle calculation
        if ($distToCurrent < self::MIN_NOISE_DISTANCE_METERS) {
            return false;
        }

        // "Spike" detection: current point is far, but next point is close to last valid
        // This indicates the GPS jumped away and came back
        if ($distToCurrent > self::MIN_NOISE_DISTANCE_METERS * 2 &&
            $distDirectToNext < $distToCurrent * 0.5) {
            return true;
        }

        // Angle-based detection: sharp deviation from trajectory
        $angle = $this->calculateAngleDegrees(
            $lastValidPoint['_coord'],
            $currentPoint['_coord'],
            $nextPoint['_coord']
        );

        if ($angle !== null && $angle < self::NOISE_ANGLE_THRESHOLD_DEG) {
            // Sharp turnaround - check if it's a spike (returns toward origin)
            $returnDistance = $this->haversineDistance($currentPoint['_coord'], $lastValidPoint['_coord']);
            $forwardDistance = $this->haversineDistance($nextPoint['_coord'], $lastValidPoint['_coord']);

            // If next point is closer to last valid than current, it's likely noise
            if ($forwardDistance < $returnDistance * 0.7) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the next valid (non-noisy) point in the buffer.
     *
     * @param array $buffer
     * @param array $lastValidPoint
     * @param int $startIndex Index to start searching from
     * @return array|null
     */
    private function findNextValidPoint(array $buffer, array $lastValidPoint, int $startIndex): ?array
    {
        $consecutiveNoise = 0;

        for ($i = $startIndex; $i < count($buffer); $i++) {
            $point = $buffer[$i];
            $noiseInfo = $this->detectNoiseSimple($point, $lastValidPoint);

            if (!$noiseInfo['isNoise']) {
                return $point;
            }

            $consecutiveNoise++;

            // If too many consecutive noisy points, accept the next one anyway
            // to avoid infinite interpolation
            if ($consecutiveNoise >= self::CONSECUTIVE_NOISE_LIMIT && isset($buffer[$i + 1])) {
                return $buffer[$i + 1];
            }
        }

        return null;
    }

    /**
     * Simplified noise detection for look-ahead validation.
     * Uses only speed-based detection to avoid recursion.
     *
     * @param array $point
     * @param array $lastValidPoint
     * @return array
     */
    private function detectNoiseSimple(array $point, array $lastValidPoint): array
    {
        $distance = $this->haversineDistance($lastValidPoint['_coord'], $point['_coord']);
        $timeDelta = max(1, abs($point['_timestamp'] - $lastValidPoint['_timestamp']));
        $speed = $distance / $timeDelta;

        $isNoise = ($speed > self::MAX_REALISTIC_SPEED_MPS && $distance > self::MIN_NOISE_DISTANCE_METERS)
            || ($timeDelta < self::JUMP_FILTER_TIME_SECONDS && $distance > self::JUMP_FILTER_DISTANCE_METERS);

        return ['isNoise' => $isNoise];
    }

    /**
     * Interpolate a noisy point between two valid points.
     *
     * Uses linear interpolation based on timestamps to place the point
     * on a realistic path between the valid reference points.
     *
     * @param array $noisyPoint The point to interpolate
     * @param array $startPoint Valid point before the noise
     * @param array $endPoint Valid point after the noise
     * @return array Interpolated point with updated coordinates
     */
    private function interpolatePoint(array $noisyPoint, array $startPoint, array $endPoint): array
    {
        // Calculate interpolation factor based on time
        $totalTime = $endPoint['_timestamp'] - $startPoint['_timestamp'];
        $elapsedTime = $noisyPoint['_timestamp'] - $startPoint['_timestamp'];

        // Clamp factor to [0, 1]
        $factor = $totalTime > 0 ? max(0.0, min(1.0, $elapsedTime / $totalTime)) : 0.5;

        return $this->createInterpolatedPoint(
            $noisyPoint,
            $startPoint['_coord'],
            $endPoint['_coord'],
            $factor
        );
    }

    /**
     * Create an interpolated point with new coordinates.
     *
     * @param array $originalPoint Original point (preserves metadata)
     * @param array $startCoord Start coordinate [lat, lon]
     * @param array $endCoord End coordinate [lat, lon]
     * @param float $factor Interpolation factor (0.0 = start, 1.0 = end)
     * @return array
     */
    private function createInterpolatedPoint(array $originalPoint, array $startCoord, array $endCoord, float $factor): array
    {
        // Linear interpolation of coordinates
        $interpolatedLat = $startCoord[0] + ($endCoord[0] - $startCoord[0]) * $factor;
        $interpolatedLon = $startCoord[1] + ($endCoord[1] - $startCoord[1]) * $factor;

        // Update the row's coordinate
        $row = $originalPoint['_row'];
        $row->coordinate = [$interpolatedLat, $interpolatedLon];

        return [
            '_row' => $row,
            '_coord' => [$interpolatedLat, $interpolatedLon],
            '_timestamp' => $originalPoint['_timestamp'],
            '_is_interpolated' => true,
        ];
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


