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
            // Get GPS device ID directly - avoid loading full relationship
            $gpsDeviceId = $tractor->gpsDevice?->id;
            if (!$gpsDeviceId) {
                return response()->streamJson(new \EmptyIterator());
            }

            // Use range-based date filter for optimal index utilization
            // This is faster than DATE(date_time) = ? which prevents index usage
            $startOfDay = $date->copy()->startOfDay()->format('Y-m-d H:i:s');
            $endOfDay = $date->copy()->endOfDay()->format('Y-m-d H:i:s');

            // Fast existence check with range-based query (uses composite index)
            $hasData = DB::table('gps_data')
                ->where('gps_device_id', $gpsDeviceId)
                ->where('date_time', '>=', $startOfDay)
                ->where('date_time', '<=', $endOfDay)
                ->limit(1)
                ->exists();

            if (!$hasData) {
                $lastPoint = $this->getLastPointFromPreviousDateRaw($gpsDeviceId, $startOfDay);
                if ($lastPoint) {
                    return response()->streamJson($this->yieldSinglePoint($lastPoint));
                }
                return response()->streamJson(new \EmptyIterator());
            }

            // Stream raw rows without Eloquent model hydration
            // This is significantly faster than cursor() with models
            return response()->streamJson(
                $this->streamPathPointsRaw($gpsDeviceId, $startOfDay, $endOfDay)
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
     * @param int $gpsDeviceId
     * @param string $startOfDay
     * @param string $endOfDay
     * @return \Generator
     */
    private function streamPathPointsRaw(int $gpsDeviceId, string $startOfDay, string $endOfDay): \Generator
    {
        $pdo = DB::connection()->getPdo();

        // Use unbuffered query for true streaming with minimal memory
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        // Range-based query uses composite index (gps_device_id, date_time) efficiently
        $stmt = $pdo->prepare('
            SELECT id, coordinate, speed, status, directions, date_time
            FROM gps_data
            WHERE gps_device_id = ?
              AND date_time >= ?
              AND date_time <= ?
            ORDER BY date_time ASC
        ');
        $stmt->execute([$gpsDeviceId, $startOfDay, $endOfDay]);

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
     * @param int $gpsDeviceId
     * @param string $startOfDay Start of the current day (used as upper bound)
     * @return object|null
     */
    private function getLastPointFromPreviousDateRaw(int $gpsDeviceId, string $startOfDay): ?object
    {
        // Use range query for index optimization
        return DB::table('gps_data')
            ->select(['id', 'coordinate', 'speed', 'status', 'directions', 'date_time'])
            ->where('gps_device_id', $gpsDeviceId)
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
     * Yields formatted response arrays immediately.
     *
     * @param \PDOStatement $stmt
     * @return \Generator
     */
    private function buildPathFromRawStream(\PDOStatement $stmt): \Generator
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

        while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
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


