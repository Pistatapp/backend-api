<?php

namespace App\Services;

use App\Models\Tractor;
use App\Traits\GpsReadConnection;
use Carbon\Carbon;
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
    private bool $enablePathCorrection = true;

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

            // Stream raw rows without Eloquent model hydration (single query; fallback handled in stream)
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

        $hadPoints = false;
        foreach ($this->processStreamOptimized($stmt) as $point) {
            $hadPoints = true;
            yield $point;
        }

        $this->restoreBufferedQueryMode();

        if (!$hadPoints) {
            $lastPoint = $this->getLastPointFromPreviousDateRaw($tractorId, $startOfDay);
            if ($lastPoint) {
                yield from $this->yieldSinglePoint($lastPoint);
            }
        }
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
        $hasSeenMovement = false;
        $lastPointType = null;
        $inStoppageSegment = false;
        $stoppageDuration = 0;
        $deferredRow = null;
        $stoppageStartedAtFirstPoint = false;
        $movementBuffer = [];
        $movementBufferSize = 0;
        $startingPointAssigned = false;
        $firstPointProcessed = false;
        $prevTimestamp = null;
        $lastDateTime = null;

        $correctionBatch = [];
        $correctionBatchSize = 0;
        $pendingRows = [];
        $pendingIndex = 0;
        $pathCorrectionEnabled = $this->enablePathCorrection && $this->pathCorrector !== null;

        while (true) {
            if ($pendingIndex < count($pendingRows)) {
                $row = $pendingRows[$pendingIndex++];
            } else {
                $pendingRows = [];
                $pendingIndex = 0;

                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row === false) {
                    if ($pathCorrectionEnabled && $correctionBatchSize > 0) {
                        $this->processCorrectionBatch($correctionBatch, $pendingRows);
                        $correctionBatch = [];
                        $correctionBatchSize = 0;
                        continue;
                    }
                    break;
                }

                if ($pathCorrectionEnabled) {
                    $correctionBatch[] = $row;
                    $correctionBatchSize++;
                    if ($correctionBatchSize >= self::GPS_CORRECTION_BATCH_SIZE) {
                        $this->processCorrectionBatch($correctionBatch, $pendingRows);
                        $correctionBatch = [];
                        $correctionBatchSize = 0;
                    }
                    continue;
                }
            }

            $dateTime = $row['date_time'];
            if ($dateTime === $lastDateTime) {
                continue;
            }
            $lastDateTime = $dateTime;

            $speed = (int) $row['speed'];
            $status = (int) $row['status'];
            $isMovement = ($status === 1 && $speed > 0);
            $isStoppage = ($speed === 0);
            $isFirstPoint = !$firstPointProcessed;
            $timestamp = $this->parseDateTimeToUnixTimestamp($dateTime);

            if ($inStoppageSegment && $prevTimestamp !== null && $isStoppage) {
                $stoppageDuration += max(0, $timestamp - $prevTimestamp);
            }

            if ($isMovement) {
                $hasSeenMovement = true;

                if ($inStoppageSegment && $deferredRow !== null) {
                    if ($stoppageDuration >= self::MIN_STOPPAGE_SECONDS || $stoppageStartedAtFirstPoint) {
                        yield $this->formatPointFromRow($deferredRow, false, false, true, $stoppageDuration);
                    }
                    $deferredRow = null;
                }

                $inStoppageSegment = false;
                $stoppageDuration = 0;
                $stoppageStartedAtFirstPoint = false;

                $movementBuffer[] = $row;
                $movementBufferSize++;

                if ($movementBufferSize === self::MOVEMENT_BUFFER_SIZE) {
                    $firstRow = array_shift($movementBuffer);
                    $movementBufferSize--;
                    $isStart = !$startingPointAssigned;
                    if ($isStart) {
                        $startingPointAssigned = true;
                    }
                    yield $this->formatPointFromRow($firstRow, $isStart, false, false, 0);
                } elseif ($movementBufferSize > self::MOVEMENT_BUFFER_SIZE) {
                    $shiftedRow = array_shift($movementBuffer);
                    $movementBufferSize--;
                    yield $this->formatPointFromRow($shiftedRow, false, false, false, 0);
                }

                $lastPointType = 'movement';
            } elseif ($isStoppage) {
                foreach ($movementBuffer as $bufferedRow) {
                    yield $this->formatPointFromRow($bufferedRow, false, false, false, 0);
                }
                $movementBuffer = [];
                $movementBufferSize = 0;

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

        foreach ($movementBuffer as $bufferedRow) {
            yield $this->formatPointFromRow($bufferedRow, false, false, false, 0);
        }

        if ($inStoppageSegment && $deferredRow !== null) {
            if ($stoppageDuration >= self::MIN_STOPPAGE_SECONDS || $stoppageStartedAtFirstPoint) {
                yield $this->formatPointFromRow($deferredRow, false, false, true, $stoppageDuration);
            }
        }
    }

    /**
     * Format a raw GPS row for API output.
     */
    private function formatPointFromRow(
        array $row,
        bool $isStartingPoint,
        bool $isEndingPoint,
        bool $isStopped,
        int $stoppageTime
    ): array {
        return $this->formatPointArray(
            (int) $row['id'],
            $row['coordinate'],
            (int) $row['speed'],
            (int) $row['status'],
            $row['directions'],
            $row['date_time'],
            $isStartingPoint,
            $isEndingPoint,
            $isStopped,
            $stoppageTime
        );
    }

    /**
     * Parse Y-m-d H:i:s timestamps without strtotime overhead.
     */
    private function parseDateTimeToUnixTimestamp(?string $dateTime): int
    {
        if (!$dateTime || !isset($dateTime[18])) {
            return time();
        }

        return mktime(
            (int) ($dateTime[11] . $dateTime[12]),
            (int) ($dateTime[14] . $dateTime[15]),
            (int) ($dateTime[17] . $dateTime[18]),
            (int) ($dateTime[5] . $dateTime[6]),
            (int) ($dateTime[8] . $dateTime[9]),
            (int) ($dateTime[0] . $dateTime[1] . $dateTime[2] . $dateTime[3])
        );
    }

    /**
     * Parse coordinate from JSON string, comma-separated string, or array.
     *
     * @return array{0: float, 1: float}
     */
    private function parseCoordinate(mixed $coordinate): array
    {
        $lat = 0.0;
        $lon = 0.0;

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

        return [$lat, $lon];
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
        [$lat, $lon] = $this->parseCoordinate($coordinate);

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

        $pointsToCorrect = [];
        foreach ($correctionBatch as $row) {
            [$lat, $lon] = $this->parseCoordinate($row['coordinate']);
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
                $row['coordinate'] = [$corrected['lat'], $corrected['lon']];
            }
            $correctedRowsBuffer[] = $row;
        }
    }
}
