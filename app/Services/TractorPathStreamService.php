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
    private const MOVEMENT_BUFFER_SIZE = 2;

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

    private ?bool $hasBatchIndexColumn = null;

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

            // gps_data.date_time is the normalized device/event timestamp stored
            // as a Tehran civil-time string. A route day is therefore a strict
            // Tehran calendar day; widening this window to UTC wall-clock values
            // leaks the previous night's points into today's route.
            [$startOfDay, $endOfDay] = $this->resolvePathDateWindow($date);

            // Stream raw rows without Eloquent model hydration (single query).
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
     * Half-open [start, end) string window for one Tehran civil day.
     *
     * @return array{0: string, 1: string}
     */
    private function resolvePathDateWindow(Carbon $date): array
    {
        $timezone = config('app.timezone', 'Asia/Tehran');
        $localStart = $date->copy()->setTimezone($timezone)->startOfDay();
        $nextLocalStart = $localStart->copy()->addDay();

        return [
            $localStart->format('Y-m-d H:i:s'),
            $nextLocalStart->format('Y-m-d H:i:s'),
        ];
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
        $hasBatchIndex = $this->hasGpsDataColumn('batch_index');
        $batchIndexSelect = $hasBatchIndex ? ', batch_index' : '';
        $orderBy = $hasBatchIndex
            ? 'ORDER BY date_time ASC, (batch_index IS NULL) ASC, batch_index ASC, id ASC'
            : 'ORDER BY date_time ASC, id ASC';

        // Fetch as associative array (faster than FETCH_OBJ)
        // Select only required columns in optimal order
        $stmt = $pdo->prepare('
            SELECT id, coordinate, speed, status, directions, date_time'.$batchIndexSelect.'
            FROM gps_data
            WHERE tractor_id = ?
              AND date_time >= ?
              AND date_time < ?
            '.$orderBy.'
        ');
        $stmt->execute([$tractorId, $startOfDay, $endOfDay]);

        $rawRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->restoreBufferedQueryMode();

        // Collapse replay duplicates before correction/movement (correction would
        // otherwise assign different smoothed coords to the same logical point).
        $rawRows = $this->collapseLogicalDuplicateRows($rawRows);

        if ($rawRows === []) {
            return;
        }

        // Historical API must be lossless after persistence. The previous
        // movement/stoppage state machine intentionally deferred and omitted
        // many speed=0 points, which made DB and map trails diverge. Keep every
        // logical row in device-time order; only exact replay duplicates were
        // collapsed above. Coordinate correction remains point-preserving.
        yield from $this->yieldCompleteTrail($rawRows);
    }

    /**
     * Yield every persisted logical row while preserving the existing response
     * keys consumed by Android/OSMDroid.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function yieldCompleteTrail(array $rows): \Generator
    {
        if ($this->enablePathCorrection && $this->pathCorrector !== null) {
            $correctedRows = [];
            foreach (array_chunk($rows, self::GPS_CORRECTION_BATCH_SIZE) as $batch) {
                $this->processCorrectionBatch($batch, $correctedRows);
            }
            $rows = $correctedRows;
        }

        $lastIndex = count($rows) - 1;
        foreach ($rows as $index => $row) {
            yield $this->formatPointFromRow(
                $row,
                $index === 0,
                $index === $lastIndex,
                (int) ($row['speed'] ?? 0) === 0,
                0
            );
        }
    }

    private function hasGpsDataColumn(string $column): bool
    {
        if ($this->hasBatchIndexColumn !== null && $column === 'batch_index') {
            return $this->hasBatchIndexColumn;
        }

        try {
            $database = (string) config('database.connections.mysql_gps_read.database');
            $row = $this->gpsReadSelectOne(
                'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                [$database, 'gps_data', $column]
            );
            $exists = ((int) ($row->c ?? 0)) > 0;
        } catch (\Throwable) {
            $exists = false;
        }

        if ($column === 'batch_index') {
            $this->hasBatchIndexColumn = $exists;
        }

        return $exists;
    }

    /**
     * Minimal trail: every point with a coordinate change (or first/last).
     * Used when the rich movement detector under-emits.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function yieldSimpleTrail(array $rows): \Generator
    {
        $seenLogicalKeys = [];
        $count = count($rows);
        foreach ($rows as $index => $row) {
            $dateTime = (string) ($row['date_time'] ?? '');
            if ($this->isDuplicateLogicalPoint($seenLogicalKeys, $dateTime, $row['coordinate'] ?? null)) {
                continue;
            }
            yield $this->formatPointFromRow($row, $index === 0, $index === $count - 1, false, 0);
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
     * Process stream with inline optimizations - single loop, minimal function calls.
     * This is the hot path - every micro-optimization matters here.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function processStreamOptimized(array $rows): \Generator
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
        $seenLogicalKeys = [];
        $prevLat = null;
        $prevLon = null;

        $correctionBatch = [];
        $correctionBatchSize = 0;
        $pendingRows = [];
        $pendingIndex = 0;
        $pathCorrectionEnabled = $this->enablePathCorrection && $this->pathCorrector !== null;
        $rowCursor = 0;
        $rowCount = count($rows);
        $pendingPoint = null;

        while (true) {
            if ($pendingIndex < count($pendingRows)) {
                $row = $pendingRows[$pendingIndex++];
            } else {
                $pendingRows = [];
                $pendingIndex = 0;

                if ($pathCorrectionEnabled) {
                    if ($rowCursor >= $rowCount) {
                        if ($correctionBatchSize > 0) {
                            $this->processCorrectionBatch($correctionBatch, $pendingRows);
                            $correctionBatch = [];
                            $correctionBatchSize = 0;
                            continue;
                        }
                        break;
                    }

                    while ($rowCursor < $rowCount && $correctionBatchSize < self::GPS_CORRECTION_BATCH_SIZE) {
                        $correctionBatch[] = $rows[$rowCursor++];
                        $correctionBatchSize++;
                    }
                    $this->processCorrectionBatch($correctionBatch, $pendingRows);
                    $correctionBatch = [];
                    $correctionBatchSize = 0;
                    continue;
                }

                if ($rowCursor >= $rowCount) {
                    break;
                }
                $row = $rows[$rowCursor++];
            }

            $dateTime = $row['date_time'];
            [$lat, $lon] = $this->parseCoordinate($row['coordinate'] ?? null);

            // Collapse logical duplicates anywhere in the day (replay blocks append
            // identical date_time+coordinate rows non-consecutively). Ordering stays
            // date_time ASC — first occurrence wins; received time is not used here.
            if ($this->isDuplicateLogicalPoint($seenLogicalKeys, $dateTime, $row['coordinate'] ?? null)) {
                continue;
            }

            $speed = (int) $row['speed'];
            // Speed alone is unreliable (many devices report 0 while coordinates move).
            // Live WS still updates the marker from coordinates — path must do the same.
            $movedMeters = 0.0;
            if ($prevLat !== null && $prevLon !== null) {
                $movedMeters = $this->haversineMeters($prevLat, $prevLon, $lat, $lon);
            }
            $isMovement = ($speed > 0) || ($movedMeters >= 2.0);
            $isStoppage = ! $isMovement;
            $isFirstPoint = ! $firstPointProcessed;
            $timestamp = $this->parseDateTimeToUnixTimestamp($dateTime);

            if ($inStoppageSegment && $prevTimestamp !== null && $isStoppage) {
                $stoppageDuration += max(0, $timestamp - $prevTimestamp);
            }

            if ($isMovement) {
                $hasSeenMovement = true;

                if ($inStoppageSegment && $deferredRow !== null) {
                    if ($stoppageDuration >= self::MIN_STOPPAGE_SECONDS || $stoppageStartedAtFirstPoint) {
                        if ($out = $this->enqueuePathPoint($pendingPoint, $this->formatPointFromRow($deferredRow, false, false, true, $stoppageDuration))) {
                            yield $out;
                        }
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
                    $isStart = ! $startingPointAssigned;
                    if ($isStart) {
                        $startingPointAssigned = true;
                    }
                    if ($out = $this->enqueuePathPoint($pendingPoint, $this->formatPointFromRow($firstRow, $isStart, false, false, 0))) {
                        yield $out;
                    }
                } elseif ($movementBufferSize > self::MOVEMENT_BUFFER_SIZE) {
                    $shiftedRow = array_shift($movementBuffer);
                    $movementBufferSize--;
                    if ($out = $this->enqueuePathPoint($pendingPoint, $this->formatPointFromRow($shiftedRow, false, false, false, 0))) {
                        yield $out;
                    }
                }

                $lastPointType = 'movement';
            } elseif ($isStoppage) {
                foreach ($movementBuffer as $bufferedRow) {
                    if ($out = $this->enqueuePathPoint($pendingPoint, $this->formatPointFromRow($bufferedRow, false, false, false, 0))) {
                        yield $out;
                    }
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
            $prevLat = $lat;
            $prevLon = $lon;
            $firstPointProcessed = true;
        }

        foreach ($movementBuffer as $bufferedRow) {
            if ($out = $this->enqueuePathPoint($pendingPoint, $this->formatPointFromRow($bufferedRow, false, false, false, 0))) {
                yield $out;
            }
        }

        if ($inStoppageSegment && $deferredRow !== null) {
            if ($stoppageDuration >= self::MIN_STOPPAGE_SECONDS || $stoppageStartedAtFirstPoint) {
                if ($out = $this->enqueuePathPoint($pendingPoint, $this->formatPointFromRow($deferredRow, false, false, true, $stoppageDuration))) {
                    yield $out;
                }
            }
        }

        if ($pendingPoint !== null) {
            $pendingPoint['is_ending_point'] = true;
            yield $pendingPoint;
        }
    }

    /**
     * Queue one formatted point so the previous point can be yielded before the next.
     *
     * @param  array<string, mixed>|null  $pending
     * @param  array<string, mixed>  $point
     * @return array<string, mixed>|null
     */
    private function enqueuePathPoint(?array &$pending, array $point): ?array
    {
        $ready = $pending;
        $pending = $point;

        return $ready;
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }

    /**
     * Stable key for coordinate equality checks during path streaming.
     */
    private function coordinateDedupeKey(mixed $coordinate): string
    {
        [$lat, $lon] = $this->parseCoordinate($coordinate);

        return sprintf('%.6f,%.6f', $lat, $lon);
    }

    /**
     * Track date_time + coordinate pairs seen in the current path stream.
     * Returns true when this logical point was already processed.
     *
     * @param  array<string, true>  $seen
     */
    private function isDuplicateLogicalPoint(array &$seen, string $dateTime, mixed $coordinate): bool
    {
        $key = $dateTime.'|'.$this->coordinateDedupeKey($coordinate);
        if (isset($seen[$key])) {
            return true;
        }

        $seen[$key] = true;

        return false;
    }

    /**
     * Keep first row per (date_time, coordinate) in date_time order.
     * Replay appends duplicate blocks non-consecutively — pre-filtering here
     * keeps correction and movement stages from double-emitting the trail.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function collapseLogicalDuplicateRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $seen = [];
        $collapsed = [];

        foreach ($rows as $row) {
            $dateTime = (string) ($row['date_time'] ?? '');
            if ($this->isDuplicateLogicalPoint($seen, $dateTime, $row['coordinate'] ?? null)) {
                continue;
            }

            $collapsed[] = $row;
        }

        return $collapsed;
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
