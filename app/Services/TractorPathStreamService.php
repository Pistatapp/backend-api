<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for streaming tractor GPS path data.
 *
 * Processing pipeline:
 * 1. Fetch raw GPS data from database
 * 2. Detect and mark stoppages
 * 3. Format and stream results
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
     * Processes raw rows directly without model hydration.
     *
     * @param \PDOStatement $stmt
     * @return \Generator
     */
    private function buildPathFromRawStream(\PDOStatement $stmt): \Generator
    {
        yield from $this->processStream($this->fetchRowsAsGenerator($stmt));
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
     * Process the stream with stoppage detection.
     *
     * @param \Generator $points
     * @return \Generator
     */
    private function processStream(\Generator $points): \Generator
    {
        $state = $this->initializeStoppageState();

        foreach ($points as $row) {
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
