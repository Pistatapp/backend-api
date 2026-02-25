<?php

namespace App\Services;

use App\Models\Tractor;
use App\Traits\GpsReadConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TractorPathStreamService
{
    use GpsReadConnection;
    // Stoppage detection constants
    private const MIN_STOPPAGE_SECONDS = 60;

    // Movement detection constants
    private const MOVEMENT_BUFFER_SIZE = 3;

    // Pre-computed time format for zero stoppage (most common case)
    private const ZERO_STOPPAGE_TIME = '00:00:00';

    // GPS path correction batch size (process in chunks to maintain streaming performance)
    private const GPS_CORRECTION_BATCH_SIZE = 500;

    /**
     * GPS Path Corrector Service instance
     *
     * @var GpsPathCorrectorService|null
     */
    private ?GpsPathCorrectorService $pathCorrector = null;

    /**
     * Whether to enable GPS path correction
     *
     * @var bool
     */
    private bool $enablePathCorrection = false;

    /**
     * Retrieves the tractor movement path for a specific date using GPS data analysis.
     * Optimized for sub-3s response times using raw queries and minimal processing.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @param bool $enablePathCorrection Whether to apply GPS path correction filters (default: true)
     * @return \Illuminate\Http\StreamedResponse
     */
    public function getTractorPath(Tractor $tractor, Carbon $date, bool $enablePathCorrection = true)
    {
        try {
            $this->enablePathCorrection = $enablePathCorrection;

            // Initialize path corrector if enabled
            if ($this->enablePathCorrection) {
                $this->pathCorrector = app(GpsPathCorrectorService::class);
            }

            $tractorId = $tractor->id;

            // Use range-based date filter for optimal index utilization
            $startOfDay = $date->copy()->startOfDay()->format('Y-m-d H:i:s');
            $endOfDay = $date->copy()->endOfDay()->format('Y-m-d H:i:s');

            // Fast existence check with range-based query (uses composite index)
            // Uses read-optimized connection with READ UNCOMMITTED isolation
            $hasData = $this->gpsReadTable('gps_data')
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
     * Uses read-optimized connection with READ UNCOMMITTED isolation
     * to prevent write operations from blocking reads.
     */
    private function streamPathPointsRaw(int $tractorId, string $startOfDay, string $endOfDay): \Generator
    {
        $pdo = $this->getGpsReadPdo();

        // Fetch as associative array (faster than FETCH_OBJ)
        // Select only required columns in optimal order
        $stmt = $pdo->prepare('
            SELECT id, coordinate, speed, status, directions, date_time
            FROM gps_data
            WHERE tractor_id = ?
              AND date_time >= ?
              AND date_time <= ?
            ORDER BY date_time ASC
        ');
        $stmt->execute([$tractorId, $startOfDay, $endOfDay]);

        yield from $this->processStreamOptimized($stmt);

        // Restore buffered query mode
        $this->restoreBufferedQueryMode();
    }

    /**
     * Yield a single point formatted for response.
     */
    private function yieldSinglePoint(object $point): \Generator
    {
        yield $this->formatPointArray(
            (int) $point->id,
            $point->coordinate,
            (int) $point->speed,
            (int) $point->status,
            $point->directions,
            $point->date_time,
            false,
            false,
            false,
            0
        );
    }

    /**
     * Get the last point from previous date using raw query.
     * Uses read-optimized connection with READ UNCOMMITTED isolation.
     */
    private function getLastPointFromPreviousDateRaw(int $tractorId, string $startOfDay): ?object
    {
        return $this->gpsReadTable('gps_data')
            ->select(['id', 'coordinate', 'speed', 'status', 'directions', 'date_time'])
            ->where('tractor_id', $tractorId)
            ->where('date_time', '<', $startOfDay)
            ->orderByDesc('date_time')
            ->limit(1)
            ->first();
    }

    /**
     * Process stream with inline optimizations - single loop, minimal function calls.
     * This is the hot path - every micro-optimization matters here.
     */
    private function processStreamOptimized(\PDOStatement $stmt): \Generator
    {
        // State variables (avoid array overhead)
        $hasSeenMovement = false;
        $lastPointType = null;
        $inStoppageSegment = false;
        $stoppageDuration = 0;
        $deferredRow = null;
        $stoppageStartedAtFirstPoint = false;
        $movementBuffer = [];
        $startingPointAssigned = false;
        $firstPointProcessed = false;
        $prevTimestamp = null;

        // Batch buffer for GPS path correction
        $correctionBatch = [];
        $correctedRowsBuffer = []; // Buffer for corrected rows ready to process

        // Process rows with FETCH_ASSOC (faster than FETCH_OBJ)
        while (true) {
            // Try to get next row from database
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            // If we have a new row, handle it
            if ($row !== false) {
                if ($this->enablePathCorrection && $this->pathCorrector !== null) {
                    $correctionBatch[] = $row;

                    // Process batch when it reaches the batch size
                    if (count($correctionBatch) >= self::GPS_CORRECTION_BATCH_SIZE) {
                        $this->processCorrectionBatch($correctionBatch, $correctedRowsBuffer);
                        $correctionBatch = [];
                    }
                } else {
                    // Correction disabled, use row as-is
                    $correctedRowsBuffer[] = $row;
                }
            }

            // Process rows from buffer (corrected or uncorrected)
            if (!empty($correctedRowsBuffer)) {
                $row = array_shift($correctedRowsBuffer);
            } elseif ($row === false) {
                // No more rows from database and buffer is empty
                break;
            } else {
                // Row is in correction batch, continue to next iteration
                continue;
            }
            $speed = (int) $row['speed'];
            $status = (int) $row['status'];
            $isMovement = ($status === 1 && $speed > 0);
            $isStoppage = ($speed === 0);
            $isFirstPoint = !$firstPointProcessed;

            // Parse timestamp inline (avoid function call overhead)
            $dateTime = $row['date_time'];
            $timestamp = ($dateTime && isset($dateTime[18]))
                ? (int) strtotime($dateTime)
                : time();

            // Update stoppage duration
            if ($inStoppageSegment && $prevTimestamp !== null && $isStoppage) {
                $stoppageDuration += max(0, $timestamp - $prevTimestamp);
            }

            if ($isMovement) {
                $hasSeenMovement = true;

                // Finalize deferred stoppage if exists
                if ($inStoppageSegment && $deferredRow !== null) {
                    if ($stoppageDuration >= self::MIN_STOPPAGE_SECONDS || $stoppageStartedAtFirstPoint) {
                        yield $this->formatPointArray(
                            (int) $deferredRow['id'],
                            $deferredRow['coordinate'],
                            (int) $deferredRow['speed'],
                            (int) $deferredRow['status'],
                            $deferredRow['directions'],
                            $deferredRow['date_time'],
                            false,
                            false,
                            true,
                            $stoppageDuration
                        );
                    }
                    $deferredRow = null;
                }

                // Reset stoppage state
                $inStoppageSegment = false;
                $stoppageDuration = 0;
                $stoppageStartedAtFirstPoint = false;

                // Buffer for starting point detection
                $movementBuffer[] = $row;
                $bufferSize = count($movementBuffer);

                if ($bufferSize === self::MOVEMENT_BUFFER_SIZE) {
                    $firstRow = array_shift($movementBuffer);
                    $isStart = !$startingPointAssigned;
                    if ($isStart) {
                        $startingPointAssigned = true;
                    }
                    yield $this->formatPointArray(
                        (int) $firstRow['id'],
                        $firstRow['coordinate'],
                        (int) $firstRow['speed'],
                        (int) $firstRow['status'],
                        $firstRow['directions'],
                        $firstRow['date_time'],
                        $isStart,
                        false,
                        false,
                        0
                    );
                } elseif ($bufferSize > self::MOVEMENT_BUFFER_SIZE) {
                    $shiftedRow = array_shift($movementBuffer);
                    yield $this->formatPointArray(
                        (int) $shiftedRow['id'],
                        $shiftedRow['coordinate'],
                        (int) $shiftedRow['speed'],
                        (int) $shiftedRow['status'],
                        $shiftedRow['directions'],
                        $shiftedRow['date_time'],
                        false,
                        false,
                        false,
                        0
                    );
                }

                $lastPointType = 'movement';
            } elseif ($isStoppage) {
                // Flush movement buffer on stoppage
                foreach ($movementBuffer as $bufferedRow) {
                    yield $this->formatPointArray(
                        (int) $bufferedRow['id'],
                        $bufferedRow['coordinate'],
                        (int) $bufferedRow['speed'],
                        (int) $bufferedRow['status'],
                        $bufferedRow['directions'],
                        $bufferedRow['date_time'],
                        false,
                        false,
                        false,
                        0
                    );
                }
                $movementBuffer = [];

                if ($isFirstPoint) {
                    $deferredRow = $row;
                    $inStoppageSegment = true;
                    $stoppageDuration = 0;
                    $stoppageStartedAtFirstPoint = true;
                } elseif ($hasSeenMovement && $lastPointType !== 'stoppage') {
                    $deferredRow = $row;
                    $inStoppageSegment = true;
                    $stoppageDuration = 0;
                    $stoppageStartedAtFirstPoint = false;
                }

                $lastPointType = 'stoppage';
            }

            $prevTimestamp = $timestamp;
            $firstPointProcessed = true;
        }

        // Process remaining correction batch if any
        if ($this->enablePathCorrection && $this->pathCorrector !== null && !empty($correctionBatch)) {
            $this->processCorrectionBatch($correctionBatch, $correctedRowsBuffer);
        }

        // Process any remaining rows from buffer
        while (!empty($correctedRowsBuffer)) {
            $row = array_shift($correctedRowsBuffer);

            $speed = (int) $row['speed'];
            $status = (int) $row['status'];
            $isMovement = ($status === 1 && $speed > 0);
            $isStoppage = ($speed === 0);
            $isFirstPoint = !$firstPointProcessed;

            // Parse timestamp
            $dateTime = $row['date_time'];
            $timestamp = ($dateTime && isset($dateTime[18]))
                ? (int) strtotime($dateTime)
                : time();

            // Update stoppage duration
            if ($inStoppageSegment && $prevTimestamp !== null && $isStoppage) {
                $stoppageDuration += max(0, $timestamp - $prevTimestamp);
            }

            if ($isMovement) {
                $hasSeenMovement = true;

                // Finalize deferred stoppage if exists
                if ($inStoppageSegment && $deferredRow !== null) {
                    if ($stoppageDuration >= self::MIN_STOPPAGE_SECONDS || $stoppageStartedAtFirstPoint) {
                        yield $this->formatPointArray(
                            (int) $deferredRow['id'],
                            $deferredRow['coordinate'],
                            (int) $deferredRow['speed'],
                            (int) $deferredRow['status'],
                            $deferredRow['directions'],
                            $deferredRow['date_time'],
                            false,
                            false,
                            true,
                            $stoppageDuration
                        );
                    }
                    $deferredRow = null;
                }

                // Reset stoppage state
                $inStoppageSegment = false;
                $stoppageDuration = 0;
                $stoppageStartedAtFirstPoint = false;

                // Buffer for starting point detection
                $movementBuffer[] = $row;
                $bufferSize = count($movementBuffer);

                if ($bufferSize === self::MOVEMENT_BUFFER_SIZE) {
                    $firstRow = array_shift($movementBuffer);
                    $isStart = !$startingPointAssigned;
                    if ($isStart) {
                        $startingPointAssigned = true;
                    }
                    yield $this->formatPointArray(
                        (int) $firstRow['id'],
                        $firstRow['coordinate'],
                        (int) $firstRow['speed'],
                        (int) $firstRow['status'],
                        $firstRow['directions'],
                        $firstRow['date_time'],
                        $isStart,
                        false,
                        false,
                        0
                    );
                } elseif ($bufferSize > self::MOVEMENT_BUFFER_SIZE) {
                    $shiftedRow = array_shift($movementBuffer);
                    yield $this->formatPointArray(
                        (int) $shiftedRow['id'],
                        $shiftedRow['coordinate'],
                        (int) $shiftedRow['speed'],
                        (int) $shiftedRow['status'],
                        $shiftedRow['directions'],
                        $shiftedRow['date_time'],
                        false,
                        false,
                        false,
                        0
                    );
                }

                $lastPointType = 'movement';
            } elseif ($isStoppage) {
                // Flush movement buffer on stoppage
                foreach ($movementBuffer as $bufferedRow) {
                    yield $this->formatPointArray(
                        (int) $bufferedRow['id'],
                        $bufferedRow['coordinate'],
                        (int) $bufferedRow['speed'],
                        (int) $bufferedRow['status'],
                        $bufferedRow['directions'],
                        $bufferedRow['date_time'],
                        false,
                        false,
                        false,
                        0
                    );
                }
                $movementBuffer = [];

                if ($isFirstPoint) {
                    $deferredRow = $row;
                    $inStoppageSegment = true;
                    $stoppageDuration = 0;
                    $stoppageStartedAtFirstPoint = true;
                } elseif ($hasSeenMovement && $lastPointType !== 'stoppage') {
                    $deferredRow = $row;
                    $inStoppageSegment = true;
                    $stoppageDuration = 0;
                    $stoppageStartedAtFirstPoint = false;
                }

                $lastPointType = 'stoppage';
            }

            $prevTimestamp = $timestamp;
            $firstPointProcessed = true;
        }

        // Finalize: flush remaining movement buffer
        foreach ($movementBuffer as $bufferedRow) {
            yield $this->formatPointArray(
                (int) $bufferedRow['id'],
                $bufferedRow['coordinate'],
                (int) $bufferedRow['speed'],
                (int) $bufferedRow['status'],
                $bufferedRow['directions'],
                $bufferedRow['date_time'],
                false,
                false,
                false,
                0
            );
        }

        // Finalize trailing stoppage
        if ($inStoppageSegment && $deferredRow !== null) {
            if ($stoppageDuration >= self::MIN_STOPPAGE_SECONDS || $stoppageStartedAtFirstPoint) {
                yield $this->formatPointArray(
                    (int) $deferredRow['id'],
                    $deferredRow['coordinate'],
                    (int) $deferredRow['speed'],
                    (int) $deferredRow['status'],
                    $deferredRow['directions'],
                    $deferredRow['date_time'],
                    false,
                    false,
                    true,
                    $stoppageDuration
                );
            }
        }
    }

    /**
     * Format point with inline coordinate/JSON parsing.
     * Optimized: minimal function calls, inline parsing.
     */
    private function formatPointArray(
        int $id,
        $coordinate,
        int $speed,
        int $status,
        $directions,
        ?string $dateTime,
        bool $isStartingPoint,
        bool $isEndingPoint,
        bool $isStopped,
        int $stoppageTime
    ): array {
        // Inline coordinate parsing
        $lat = 0.0;
        $lon = 0.0;
        if (is_string($coordinate)) {
            $firstChar = $coordinate[0] ?? '';
            if ($firstChar === '[') {
                // JSON array format: [lat,lon]
                $decoded = json_decode($coordinate, true);
                if ($decoded) {
                    $lat = (float) ($decoded[0] ?? 0);
                    $lon = (float) ($decoded[1] ?? 0);
                }
            } else {
                // Comma-separated format: "lat,lon"
                $parts = explode(',', $coordinate, 2);
                if (count($parts) === 2) {
                    $lat = (float) $parts[0];
                    $lon = (float) $parts[1];
                }
            }
        } elseif (is_array($coordinate)) {
            $lat = (float) ($coordinate[0] ?? 0);
            $lon = (float) ($coordinate[1] ?? 0);
        }

        // Inline directions parsing
        $parsedDirections = is_string($directions) ? json_decode($directions, true) : $directions;

        // Inline time extraction (substr is very fast)
        $timestamp = ($dateTime && isset($dateTime[18]))
            ? substr($dateTime, 11, 8)
            : '00:00:00';

        // Inline stoppage time formatting (avoid function call for common case)
        if ($stoppageTime === 0) {
            $formattedStoppage = self::ZERO_STOPPAGE_TIME;
        } else {
            $hours = (int) ($stoppageTime / 3600);
            $remaining = $stoppageTime % 3600;
            $minutes = (int) ($remaining / 60);
            $seconds = $remaining % 60;
            $formattedStoppage = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return [
            'id' => $id,
            'latitude' => $lat,
            'longitude' => $lon,
            'speed' => $speed,
            'status' => $status,
            'is_starting_point' => $isStartingPoint,
            'is_ending_point' => $isEndingPoint,
            'is_stopped' => $isStopped,
            'directions' => $parsedDirections,
            'stoppage_time' => $formattedStoppage,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Process a batch of GPS points through the correction pipeline
     *
     * @param array $correctionBatch Batch of raw database rows to correct
     * @param array $correctedRowsBuffer Reference to buffer array for storing corrected rows ready to yield
     * @return void
     */
    private function processCorrectionBatch(array $correctionBatch, array &$correctedRowsBuffer): void
    {
        if (empty($correctionBatch) || $this->pathCorrector === null) {
            return;
        }

        // Parse coordinates and prepare points for correction pipeline
        $pointsToCorrect = [];
        foreach ($correctionBatch as $row) {
            $coordinate = $row['coordinate'];
            $lat = 0.0;
            $lon = 0.0;

            // Parse coordinate
            if (is_string($coordinate)) {
                $firstChar = $coordinate[0] ?? '';
                if ($firstChar === '[') {
                    $decoded = json_decode($coordinate, true);
                    if ($decoded) {
                        $lat = (float) ($decoded[0] ?? 0);
                        $lon = (float) ($decoded[1] ?? 0);
                    }
                } else {
                    $parts = explode(',', $coordinate, 2);
                    if (count($parts) === 2) {
                        $lat = (float) $parts[0];
                        $lon = (float) $parts[1];
                    }
                }
            } elseif (is_array($coordinate)) {
                $lat = (float) ($coordinate[0] ?? 0);
                $lon = (float) ($coordinate[1] ?? 0);
            }

            $pointsToCorrect[] = [
                'lat' => $lat,
                'lon' => $lon,
                'coordinate' => [$lat, $lon],
            ];
        }

        // Apply correction through pipeline
        $correctedPoints = $this->pathCorrector->correct($pointsToCorrect);

        // Update rows with corrected coordinates and add to buffer
        foreach ($correctionBatch as $batchIndex => $row) {
            if (isset($correctedPoints[$batchIndex])) {
                $corrected = $correctedPoints[$batchIndex];
                // Update coordinate in the row
                $row['coordinate'] = json_encode([$corrected['lat'], $corrected['lon']]);
            }
            $correctedRowsBuffer[] = $row;
        }
    }
}
