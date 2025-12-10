<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TractorPathStreamService
{
    /**
     * Minimum stoppage duration in seconds to be considered significant.
     */
    private const MIN_STOPPAGE_SECONDS = 60;

    /**
     * Maximum realistic speed for a tractor in meters per second.
     * 50 km/h = ~13.9 m/s. We use a generous threshold to account for GPS inaccuracy.
     */
    private const MAX_REALISTIC_SPEED_MPS = 25.0; // ~90 km/h

    /**
     * Minimum time delta (seconds) to consider for speed calculation.
     * Prevents division by very small numbers.
     */
    private const MIN_TIME_DELTA_SECONDS = 1;

    /**
     * Maximum consecutive jumps before we consider the new location as valid.
     * If we see N consecutive "jumps", the tractor likely teleported legitimately (e.g., loaded on a trailer).
     */
    private const MAX_CONSECUTIVE_JUMPS = 3;

    /**
     * Earth radius in meters for haversine calculation.
     */
    private const EARTH_RADIUS_METERS = 6371000;

    /**
     * Corner detection threshold in degrees.
     * Angles computed at point B for triplet (A, B, C):
     *   - Straight segments yield angles near 180°
     *   - Sharp corners/turnarounds yield smaller angles (e.g., 0–140°)
     * Points with angle < threshold are candidates for smoothing.
     */
    private const CORNER_ANGLE_THRESHOLD_DEG = 140.0;

    /**
     * Turnaround detection threshold in degrees.
     * Very sharp turns (near 180° reversal) get extra smoothing.
     */
    private const TURNAROUND_ANGLE_THRESHOLD_DEG = 60.0;

    /**
     * Minimum segment distance (meters) to consider for corner detection.
     * Shorter segments are ignored to avoid amplifying GPS noise.
     */
    private const MIN_SEGMENT_DISTANCE_METERS = 2.0;

    /**
     * Window size for the smoothing algorithm.
     * Larger windows produce smoother curves but may lose detail.
     */
    private const SMOOTHING_WINDOW_SIZE = 5;

    public function __construct(
        private GpsPathCorrectionService $pathCorrectionService,
    ) {
    }

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
        // Parse coordinate - handle both JSON string and array
        $coord = $row->coordinate;
        if (is_string($coord)) {
            // Fast JSON decode - coordinate is always [lat, lon]
            $coord = json_decode($coord, true) ?: [0, 0];
        }
        $lat = $coord[0] ?? 0;
        $lon = $coord[1] ?? 0;

        // Parse directions
        $directions = $row->directions;
        if (is_string($directions)) {
            $directions = json_decode($directions, true);
        }

        // Extract time portion directly from datetime string (avoid Carbon overhead)
        // Format: "YYYY-MM-DD HH:MM:SS" -> extract "HH:MM:SS"
        $dateTime = $row->date_time;
        if (is_string($dateTime) && strlen($dateTime) >= 19) {
            $timestamp = substr($dateTime, 11, 8);
        } else {
            $timestamp = '00:00:00';
        }

        return [
            'id' => (int) $row->id,
            'latitude' => (float) $lat,
            'longitude' => (float) $lon,
            'speed' => (int) $row->speed,
            'status' => (int) $row->status,
            'is_starting_point' => $isStartingPoint,
            'is_ending_point' => $isEndingPoint,
            'is_stopped' => $isStopped,
            'directions' => $directions,
            'stoppage_time' => $stoppageTime > 0 ? gmdate('H:i:s', $stoppageTime) : '00:00:00',
            'timestamp' => $timestamp,
        ];
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
     * Filter out sudden GPS jumps from the point stream.
     *
     * Algorithm:
     * 1. For each point, calculate the implied speed from the previous valid point.
     * 2. If implied speed exceeds MAX_REALISTIC_SPEED_MPS, mark as a potential jump.
     * 3. Track consecutive jumps - if we see MAX_CONSECUTIVE_JUMPS in a row,
     *    accept the new location as valid (tractor was likely transported).
     * 4. Skip individual jump points to maintain path continuity.
     *
     * @param \Generator $points Raw GPS points generator
     * @return \Generator Filtered points with jumps removed
     */
    private function filterJumps(\Generator $points): \Generator
    {
        $lastValidPoint = null;
        $jumpBuffer = [];
        $consecutiveJumps = 0;

        foreach ($points as $row) {
            $currentCoord = $this->extractCoordinate($row);
            $currentTimestamp = $this->parseTimestampFast($row->date_time);

            // First point is always valid
            if ($lastValidPoint === null) {
                $lastValidPoint = [
                    'row' => $row,
                    'coord' => $currentCoord,
                    'timestamp' => $currentTimestamp,
                ];
                yield $row;
                continue;
            }

            $timeDelta = abs($currentTimestamp - $lastValidPoint['timestamp']);
            $distance = $this->haversineDistance(
                $lastValidPoint['coord'],
                $currentCoord
            );

            // Calculate implied speed (m/s)
            $impliedSpeed = ($timeDelta >= self::MIN_TIME_DELTA_SECONDS)
                ? $distance / $timeDelta
                : $distance; // If time delta is tiny, use distance as proxy

            $isJump = $impliedSpeed > self::MAX_REALISTIC_SPEED_MPS;

            if ($isJump) {
                $consecutiveJumps++;
                $jumpBuffer[] = $row;

                // If we've seen enough consecutive "jumps", the tractor likely
                // teleported legitimately (e.g., loaded on a trailer and moved).
                // Accept the new location as valid and flush the buffer.
                if ($consecutiveJumps >= self::MAX_CONSECUTIVE_JUMPS) {
                    // Yield all buffered points - this is a legitimate relocation
                    foreach ($jumpBuffer as $bufferedRow) {
                        yield $bufferedRow;
                    }

                    // Update last valid point to the most recent
                    $lastValidPoint = [
                        'row' => $row,
                        'coord' => $currentCoord,
                        'timestamp' => $currentTimestamp,
                    ];

                    $jumpBuffer = [];
                    $consecutiveJumps = 0;
                }
                // Otherwise, we skip this point (don't yield it)
            } else {
                // Valid point - reset jump tracking
                $consecutiveJumps = 0;

                // If we had buffered jumps but now see a valid point,
                // discard the jump buffer (those were anomalies)
                $jumpBuffer = [];

                $lastValidPoint = [
                    'row' => $row,
                    'coord' => $currentCoord,
                    'timestamp' => $currentTimestamp,
                ];

                yield $row;
            }
        }

        // At end of stream, if we have buffered jumps that didn't meet the
        // consecutive threshold, we discard them (they were GPS glitches).
        // This is intentional - we don't yield $jumpBuffer here.
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

        // Cannot compute angle without neighbors
        if (!$prev || !$next) {
            return $curr;
        }

        $prevCoord = $this->extractCoordinate($prev);
        $currCoord = $this->extractCoordinate($curr);
        $nextCoord = $this->extractCoordinate($next);

        // Check segment distances - skip if too short (GPS noise)
        $prevDist = $this->haversineDistance($prevCoord, $currCoord);
        $nextDist = $this->haversineDistance($currCoord, $nextCoord);

        if ($prevDist < self::MIN_SEGMENT_DISTANCE_METERS ||
            $nextDist < self::MIN_SEGMENT_DISTANCE_METERS) {
            return $curr;
        }

        // Calculate the angle at the center point
        $angle = $this->calculateAngleDegrees($prevCoord, $currCoord, $nextCoord);

        // Invalid angle or straight segment - no smoothing needed
        if ($angle === null || $angle >= self::CORNER_ANGLE_THRESHOLD_DEG) {
            return $curr;
        }

        // Determine smoothing strength based on angle sharpness
        $isTurnaround = $angle < self::TURNAROUND_ANGLE_THRESHOLD_DEG;

        // Apply smoothing
        return $this->applySmoothingToPoint($window, $centerIndex, $isTurnaround);
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
     * Uses a bell-curve-like distribution that gives less weight to the center
     * point and more weight to neighbors, creating a smoother curve.
     *
     * @param int $windowSize
     * @param int $centerIndex
     * @return array Weights for each position
     */
    private function getTurnaroundWeights(int $windowSize, int $centerIndex): array
    {
        $weights = [];

        // Use inverse distance from center - neighbors get more weight
        // This creates a "pulling" effect toward a smoother path
        for ($i = 0; $i < $windowSize; $i++) {
            $distFromCenter = abs($i - $centerIndex);

            if ($distFromCenter === 0) {
                // Center point gets reduced weight for turnarounds
                $weights[$i] = 0.5;
            } else {
                // Neighbors get progressively less weight as they're farther
                $weights[$i] = 1.0 / $distFromCenter;
            }
        }

        // Normalize weights
        $sum = array_sum($weights);
        if ($sum > 0) {
            foreach ($weights as &$w) {
                $w /= $sum;
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
     * Normalize the coordinate property on a row object to an array [lat, lon].
     *
     * @param object $row
     * @return void
     */
    private function normalizeRowCoordinate(object $row): void
    {
        $coord = $row->coordinate ?? null;

        if (is_string($coord)) {
            $decoded = json_decode($coord, true);
            if (is_array($decoded)) {
                $row->coordinate = [
                    (float)($decoded[0] ?? 0),
                    (float)($decoded[1] ?? 0)
                ];
            }
        }
    }

    /**
     * Extract coordinate array from a raw database row.
     *
     * @param object $row
     * @return array [lat, lon]
     */
    private function extractCoordinate(object $row): array
    {
        $coord = $row->coordinate;

        if (is_string($coord)) {
            $decoded = json_decode($coord, true);
            if (is_array($decoded)) {
                return [(float)($decoded[0] ?? 0), (float)($decoded[1] ?? 0)];
            }
        } elseif (is_array($coord)) {
            return [(float)($coord[0] ?? 0), (float)($coord[1] ?? 0)];
        }

        return [0.0, 0.0];
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
        $hasSeenMovement = false;
        $lastPointType = null;
        $inStoppageSegment = false;
        $stoppageDuration = 0;
        $deferredStoppageRow = null;
        $stoppageStartedAtFirstPoint = false;
        $movementBuffer = [];
        $startingPointAssigned = false;
        $firstPointProcessed = false;
        $prevTimestamp = null;

        foreach ($cleanedPoints as $row) {
            $speed = (int) $row->speed;
            $status = (int) $row->status;
            $isMovement = ($status === 1 && $speed > 0);
            $isStoppage = ($speed === 0);
            $isFirstPoint = !$firstPointProcessed;

            // Parse timestamp from datetime string efficiently
            // Format: "YYYY-MM-DD HH:MM:SS"
            $timestamp = $this->parseTimestampFast($row->date_time);

            // Accumulate stoppage duration
            if ($inStoppageSegment && $prevTimestamp !== null && $isStoppage) {
                $stoppageDuration += max(0, $timestamp - $prevTimestamp);
            }

            if ($isMovement) {
                $hasSeenMovement = true;

                // Finalize deferred stoppage if exists
                if ($inStoppageSegment && $deferredStoppageRow !== null) {
                    if ($stoppageDuration >= self::MIN_STOPPAGE_SECONDS || $stoppageStartedAtFirstPoint) {
                        yield $this->formatRawPoint($deferredStoppageRow, false, false, true, $stoppageDuration);
                    }
                    $deferredStoppageRow = null;
                }

                $inStoppageSegment = false;
                $stoppageDuration = 0;
                $stoppageStartedAtFirstPoint = false;

                // Buffer for starting point detection
                $movementBuffer[] = $row;

                if (count($movementBuffer) === 3) {
                    $firstRow = array_shift($movementBuffer);
                    $isStart = !$startingPointAssigned;
                    if ($isStart) {
                        $startingPointAssigned = true;
                    }
                    yield $this->formatRawPoint($firstRow, $isStart, false, false, 0);
                } elseif (count($movementBuffer) > 3) {
                    yield $this->formatRawPoint(array_shift($movementBuffer), false, false, false, 0);
                }

                $lastPointType = 'movement';

            } elseif ($isStoppage) {
                // Flush movement buffer on stoppage
                foreach ($movementBuffer as $bufferedRow) {
                    yield $this->formatRawPoint($bufferedRow, false, false, false, 0);
                }
                $movementBuffer = [];

                if ($isFirstPoint) {
                    $deferredStoppageRow = $row;
                    $inStoppageSegment = true;
                    $stoppageDuration = 0;
                    $stoppageStartedAtFirstPoint = true;
                    $lastPointType = 'stoppage';
                } elseif ($hasSeenMovement && $lastPointType !== 'stoppage') {
                    $deferredStoppageRow = $row;
                    $inStoppageSegment = true;
                    $stoppageDuration = 0;
                    $stoppageStartedAtFirstPoint = false;
                    $lastPointType = 'stoppage';
                } else {
                    $lastPointType = 'stoppage';
                }
            }

            $firstPointProcessed = true;
            $prevTimestamp = $timestamp;
        }

        // Flush remaining movement buffer
        foreach ($movementBuffer as $bufferedRow) {
            yield $this->formatRawPoint($bufferedRow, false, false, false, 0);
        }

        // Finalize trailing stoppage
        if ($inStoppageSegment && $deferredStoppageRow !== null) {
            if ($stoppageDuration >= self::MIN_STOPPAGE_SECONDS || $stoppageStartedAtFirstPoint) {
                yield $this->formatRawPoint($deferredStoppageRow, false, false, true, $stoppageDuration);
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


