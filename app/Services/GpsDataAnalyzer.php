<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Tractor;

class GpsDataAnalyzer
{
    private array $data = [];
    private array $results = [];
    private ?int $workingStartTimestamp = null;
    private ?int $workingEndTimestamp = null;

    private const MIN_STOPPAGE_DURATION_SECONDS = 60;
    private const CONSECUTIVE_MOVEMENTS_FOR_FIRST_MOVEMENT = 3;
    private const EARTH_RADIUS_KM = 6371;
    private const SECONDS_PER_HOUR = 3600;
    private const MIN_COORDINATE_COUNT = 2;
    private const METERS_PER_KILOMETER = 1000;

    // Array index constants for internal data structure
    private const IDX_LAT = 0;
    private const IDX_LON = 1;
    private const IDX_LAT_RAD = 2;
    private const IDX_LON_RAD = 3;
    private const IDX_TIMESTAMP = 4;
    private const IDX_SPEED = 5;
    private const IDX_STATUS = 6;

    /**
     * Load GPS records for a tractor on a specific date
     */
    public function loadRecordsFor(Tractor $tractor, Carbon $date): self
    {
        [$startDateTime, $endDateTime] = $tractor->getWorkingWindow($date);

        $gpsData = $tractor->gpsData()
            ->whereBetween('gps_data.date_time', [$startDateTime, $endDateTime])
            ->orderBy('gps_data.date_time')
            ->get(['date_time', 'coordinate', 'speed', 'status', 'imei']);

        $this->parseRecords($gpsData);

        return $this;
    }

    /**
     * Parse records into internal format optimized for analysis
     *
     * @param iterable $data
     */
    private function parseRecords(iterable $data): void
    {
        $this->data = [];

        foreach ($data as $record) {
            $parsedRecord = is_object($record)
                ? $this->parseObjectRecord($record)
                : $this->parseArrayRecord($record);

            if ($parsedRecord !== null) {
                $this->data[] = $parsedRecord;
            }
        }
    }

    /**
     * Parse a single object record into internal format
     */
    private function parseObjectRecord(object $record): ?array
    {
        $rawCoordinate = $record->getRawOriginal('coordinate') ?? $record->coordinate;
        $coordinate = $this->parseCoordinate($rawCoordinate);
        if ($coordinate === null) {
            return null;
        }

        [$lat, $lon] = $coordinate;
        $dateTime = $record->date_time;
        $timestamp = $dateTime instanceof Carbon
            ? $dateTime->timestamp
            : Carbon::parse($dateTime)->timestamp;

        return $this->buildDataRecord($lat, $lon, $timestamp, (int)$record->speed, (int)$record->status);
    }

    /**
     * Parse a single array record into internal format
     */
    private function parseArrayRecord(array $record): ?array
    {
        $coordinate = $this->parseCoordinate($record['coordinate'] ?? null);
        if ($coordinate === null) {
            return null;
        }

        [$lat, $lon] = $coordinate;
        $dateTime = $record['date_time'] ?? null;
        $timestamp = $dateTime instanceof Carbon
            ? $dateTime->timestamp
            : ($dateTime ? Carbon::parse($dateTime)->timestamp : time());

        return $this->buildDataRecord(
            $lat,
            $lon,
            $timestamp,
            (int)($record['speed'] ?? 0),
            (int)($record['status'] ?? 0)
        );
    }

    /**
     * Build internal data record array from parsed values
     */
    private function buildDataRecord(float $lat, float $lon, int $timestamp, int $speed, int $status): array
    {
        return [
            self::IDX_LAT => $lat,
            self::IDX_LON => $lon,
            self::IDX_LAT_RAD => deg2rad($lat),
            self::IDX_LON_RAD => deg2rad($lon),
            self::IDX_TIMESTAMP => $timestamp,
            self::IDX_SPEED => $speed,
            self::IDX_STATUS => $status,
        ];
    }

    /**
     * Parse coordinate from various formats to [lat, lon] array
     * Handles: array, JSON string, comma-separated string
     */
    private function parseCoordinate(mixed $coordinate): ?array
    {
        if ($coordinate === null) {
            return null;
        }

        // Already an array
        if (is_array($coordinate) && count($coordinate) >= self::MIN_COORDINATE_COUNT) {
            return [(float)$coordinate[0], (float)$coordinate[1]];
        }

        if (is_string($coordinate)) {
            // Try JSON decode first
            $decoded = json_decode($coordinate, true);
            if (is_array($decoded) && count($decoded) >= self::MIN_COORDINATE_COUNT) {
                return [(float)$decoded[0], (float)$decoded[1]];
            }

            // Fall back to comma-separated format
            $parts = explode(',', $coordinate);
            if (count($parts) >= self::MIN_COORDINATE_COUNT) {
                return [(float)$parts[0], (float)$parts[1]];
            }
        }

        return null;
    }

    /**
     * Analyze GPS data and calculate all metrics
     *
     * Movement = status == 1 && speed > 0
     * Stoppage = speed == 0 (stoppages < 60s counted as movement)
     * firstMovementTime = timestamp when 3 consecutive movements detected
     */
    public function analyze(
        ?Carbon $workingStartTime = null,
        ?Carbon $workingEndTime = null,
        array $polygon = []
    ): array {
        $dataCount = count($this->data);

        if ($dataCount === 0) {
            return $this->getEmptyResults();
        }

        $workingStartTs = $workingStartTime?->timestamp ?? $this->workingStartTimestamp;
        $workingEndTs = $workingEndTime?->timestamp ?? $this->workingEndTimestamp;
        $normalizedPolygon = $this->preparePolygon($polygon);
        $hasPolygon = !empty($normalizedPolygon);

        $metrics = $this->initializeMetrics();
        $state = $this->initializeState();
        $activation = $this->initializeActivationTracking();

        $previousPoint = null;

        for ($i = 0; $i < $dataCount; $i++) {
            $point = &$this->data[$i];

            if ($hasPolygon && !$this->isPointInPolygon($point, $normalizedPolygon)) {
                continue;
            }

            $pointData = $this->extractPointData($point, $i);

            // Track device activation
            $activation = $this->trackDeviceActivation($activation, $pointData);

            // Track first movement
            $activation = $this->trackFirstMovement($activation, $pointData, $i);

            if ($previousPoint === null) {
                $previousPoint = $this->initializeFirstPoint($pointData, $state);
                continue;
            }

            $timeDiff = $this->calcDuration(
                $previousPoint['timestamp'],
                $pointData['timestamp'],
                $workingStartTs,
                $workingEndTs
            );

            $this->processStateTransition(
                $pointData,
                $previousPoint,
                $state,
                $metrics,
                $timeDiff,
                $workingStartTs,
                $workingEndTs,
                $i
            );

            $previousPoint = $pointData;
        }

        $this->handleFinalState($state, $metrics, $dataCount, $workingStartTs, $workingEndTs);

        return $this->buildResults($metrics, $activation, $dataCount);
    }

    /**
     * Prepare and normalize polygon if provided
     */
    private function preparePolygon(array $polygon): array
    {
        return !empty($polygon) ? $this->normalizePolygon($polygon) : [];
    }

    /**
     * Initialize metrics counters
     */
    private function initializeMetrics(): array
    {
        return [
            'movement_distance' => 0.0,
            'movement_duration' => 0,
            'stoppage_duration' => 0,
            'stoppage_duration_while_on' => 0,
            'stoppage_duration_while_off' => 0,
            'stoppage_count' => 0,
        ];
    }

    /**
     * Initialize movement state tracking
     */
    private function initializeState(): array
    {
        return [
            'is_stopped' => false,
            'is_moving' => false,
            'stoppage_start_index' => -1,
        ];
    }

    /**
     * Initialize device activation tracking
     */
    private function initializeActivationTracking(): array
    {
        return [
            'device_on_timestamp' => null,
            'first_movement_timestamp' => null,
            'consecutive_movement_count' => 0,
            'first_consecutive_movement_index' => -1,
        ];
    }

    /**
     * Extract and structure point data for easier access
     */
    private function extractPointData(array $point, int $index): array
    {
        return [
            'lat' => $point[self::IDX_LAT],
            'lon' => $point[self::IDX_LON],
            'lat_rad' => $point[self::IDX_LAT_RAD],
            'lon_rad' => $point[self::IDX_LON_RAD],
            'timestamp' => $point[self::IDX_TIMESTAMP],
            'speed' => $point[self::IDX_SPEED],
            'status' => $point[self::IDX_STATUS],
            'index' => $index,
            'is_moving' => ($point[self::IDX_STATUS] === 1 && $point[self::IDX_SPEED] > 0),
            'is_stopped' => ($point[self::IDX_SPEED] === 0),
        ];
    }

    /**
     * Check if point is within polygon
     */
    private function isPointInPolygon(array $point, array $polygon): bool
    {
        return $this->isPointInPolygonFast(
            $point[self::IDX_LON],
            $point[self::IDX_LAT],
            $polygon
        );
    }

    /**
     * Track device activation (first status=1)
     */
    private function trackDeviceActivation(array $activation, array $pointData): array
    {
        if ($activation['device_on_timestamp'] === null && $pointData['status'] === 1) {
            $activation['device_on_timestamp'] = $pointData['timestamp'];
        }
        return $activation;
    }

    /**
     * Track first movement (3 consecutive movements)
     */
    private function trackFirstMovement(array $activation, array $pointData, int $index): array
    {
        if ($activation['first_movement_timestamp'] !== null) {
            return $activation;
        }

        if ($pointData['is_moving']) {
            if ($activation['consecutive_movement_count'] === 0) {
                $activation['first_consecutive_movement_index'] = $index;
            }
            $activation['consecutive_movement_count']++;

            if ($activation['consecutive_movement_count'] === self::CONSECUTIVE_MOVEMENTS_FOR_FIRST_MOVEMENT) {
                $activation['first_movement_timestamp'] = $this->data[$activation['first_consecutive_movement_index']][self::IDX_TIMESTAMP];
            }
        } else {
            $activation['consecutive_movement_count'] = 0;
            $activation['first_consecutive_movement_index'] = -1;
        }

        return $activation;
    }

    /**
     * Initialize state for first valid point
     */
    private function initializeFirstPoint(array $pointData, array &$state): array
    {
        if ($pointData['is_stopped']) {
            $state['is_stopped'] = true;
            $state['stoppage_start_index'] = $pointData['index'];
        } elseif ($pointData['is_moving']) {
            $state['is_moving'] = true;
        }

        return $pointData;
    }

    /**
     * Process state transitions between points
     */
    private function processStateTransition(
        array $pointData,
        array $previousPoint,
        array &$state,
        array &$metrics,
        int $timeDiff,
        ?int $workingStartTs,
        ?int $workingEndTs,
        int $currentIndex
    ): void {
        $distance = $this->haversineRad(
            $previousPoint['lat_rad'],
            $previousPoint['lon_rad'],
            $pointData['lat_rad'],
            $pointData['lon_rad']
        );

        if ($pointData['is_stopped'] && $state['is_moving']) {
            // Moving -> Stopped
            $this->handleMovingToStopped($pointData, $previousPoint, $state, $metrics, $distance, $timeDiff);
        } elseif ($pointData['is_moving'] && $state['is_stopped']) {
            // Stopped -> Moving
            $this->handleStoppedToMoving($pointData, $previousPoint, $state, $metrics, $distance, $workingStartTs, $workingEndTs, $currentIndex);
        } elseif ($pointData['is_moving'] && $state['is_moving']) {
            // Continue moving
            $metrics['movement_distance'] += $distance;
            $metrics['movement_duration'] += $timeDiff;
        } elseif ($pointData['is_stopped'] && !$state['is_stopped'] && !$state['is_moving']) {
            // Start stoppage from neutral state
            $state['is_stopped'] = true;
            $state['stoppage_start_index'] = $currentIndex;
        } elseif ($pointData['is_moving'] && !$state['is_stopped'] && !$state['is_moving']) {
            // Start moving from neutral state
            $state['is_moving'] = true;
        }
    }

    /**
     * Handle transition from moving to stopped state
     */
    private function handleMovingToStopped(
        array $pointData,
        array $previousPoint,
        array &$state,
        array &$metrics,
        float $distance,
        int $timeDiff
    ): void {
        $metrics['movement_distance'] += $distance;
        $metrics['movement_duration'] += $timeDiff;

        $state['is_moving'] = false;
        $state['is_stopped'] = true;
        $state['stoppage_start_index'] = $pointData['index'];
    }

    /**
     * Handle transition from stopped to moving state
     */
    private function handleStoppedToMoving(
        array $pointData,
        array $previousPoint,
        array &$state,
        array &$metrics,
        float $distance,
        ?int $workingStartTs,
        ?int $workingEndTs,
        int $currentIndex
    ): void {
        $stoppageStartTs = $this->data[$state['stoppage_start_index']][self::IDX_TIMESTAMP];
        $stoppageDuration = $this->calcDuration($stoppageStartTs, $pointData['timestamp'], $workingStartTs, $workingEndTs);

        if ($stoppageDuration >= self::MIN_STOPPAGE_DURATION_SECONDS) {
            $metrics['stoppage_count']++;
            $metrics['stoppage_duration'] += $stoppageDuration;
            [$onDuration, $offDuration] = $this->calcStoppageOnOff($state['stoppage_start_index'], $currentIndex, $workingStartTs, $workingEndTs);
            $metrics['stoppage_duration_while_on'] += $onDuration;
            $metrics['stoppage_duration_while_off'] += $offDuration;
        } else {
            // Short stoppage counted as movement
            $metrics['movement_duration'] += $stoppageDuration;
        }

        $metrics['movement_distance'] += $distance;

        $state['is_stopped'] = false;
        $state['is_moving'] = true;
    }

    /**
     * Handle final state after processing all points
     */
    private function handleFinalState(
        array $state,
        array &$metrics,
        int $dataCount,
        ?int $workingStartTs,
        ?int $workingEndTs
    ): void {
        if (!$state['is_stopped'] || $state['stoppage_start_index'] < 0) {
            return;
        }

        $stoppageStartTs = $this->data[$state['stoppage_start_index']][self::IDX_TIMESTAMP];
        $lastTs = $this->data[$dataCount - 1][self::IDX_TIMESTAMP];
        $stoppageDuration = $this->calcDuration($stoppageStartTs, $lastTs, $workingStartTs, $workingEndTs);

        if ($stoppageDuration >= self::MIN_STOPPAGE_DURATION_SECONDS) {
            $metrics['stoppage_count']++;
            $metrics['stoppage_duration'] += $stoppageDuration;
            [$onDuration, $offDuration] = $this->calcStoppageOnOff($state['stoppage_start_index'], $dataCount - 1, $workingStartTs, $workingEndTs);
            $metrics['stoppage_duration_while_on'] += $onDuration;
            $metrics['stoppage_duration_while_off'] += $offDuration;
        } else {
            $metrics['movement_duration'] += $stoppageDuration;
        }
    }

    /**
     * Build final results array from metrics and activation data
     */
    private function buildResults(array $metrics, array $activation, int $dataCount): array
    {
        $averageSpeed = $metrics['movement_duration'] > 0
            ? (int)($metrics['movement_distance'] * self::SECONDS_PER_HOUR / $metrics['movement_duration'])
            : 0;

        $lastPoint = $this->data[$dataCount - 1];

        $this->results = [
            'movement_distance_km' => round($metrics['movement_distance'], 1),
            'movement_distance_meters' => round($metrics['movement_distance'] * self::METERS_PER_KILOMETER, 2),
            'movement_duration_seconds' => $metrics['movement_duration'],
            'movement_duration_formatted' => $this->formatTime($metrics['movement_duration']),
            'stoppage_duration_seconds' => $metrics['stoppage_duration'],
            'stoppage_duration_formatted' => $this->formatTime($metrics['stoppage_duration']),
            'stoppage_duration_while_on_seconds' => $metrics['stoppage_duration_while_on'],
            'stoppage_duration_while_on_formatted' => $this->formatTime($metrics['stoppage_duration_while_on']),
            'stoppage_duration_while_off_seconds' => $metrics['stoppage_duration_while_off'],
            'stoppage_duration_while_off_formatted' => $this->formatTime($metrics['stoppage_duration_while_off']),
            'stoppage_count' => $metrics['stoppage_count'],
            'device_on_time' => $activation['device_on_timestamp'] !== null ? $this->formatTimestamp($activation['device_on_timestamp']) : null,
            'first_movement_time' => $activation['first_movement_timestamp'] !== null ? $this->formatTimestamp($activation['first_movement_timestamp']) : null,
            'latest_status' => $lastPoint[self::IDX_STATUS],
            'average_speed' => $averageSpeed,
        ];

        return $this->results;
    }

    /**
     * Normalize polygon coordinates once for reuse
     */
    private function normalizePolygon(array $polygon): array
    {
        $normalized = [];
        foreach ($polygon as $p) {
            if (is_string($p)) {
                $coords = explode(',', $p);
                $normalized[] = [(float)$coords[0], (float)$coords[1]];
            } else {
                $normalized[] = $p;
            }
        }
        return $normalized;
    }

    /**
     * Fast point-in-polygon check using ray casting (pre-normalized polygon)
     */
    private function isPointInPolygonFast(float $x, float $y, array $polygon): bool
    {
        $inside = false;
        $numPoints = count($polygon);
        $j = $numPoints - 1;

        for ($i = 0; $i < $numPoints; $j = $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];

            if ((($yi > $y) !== ($yj > $y)) &&
                ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Calculate duration between timestamps, clamped to working window
     */
    private function calcDuration(int $startTs, int $endTs, ?int $wsTs, ?int $weTs): int
    {
        if ($wsTs !== null) {
            $startTs = max($startTs, $wsTs);
            $endTs = max($endTs, $wsTs);
        }
        if ($weTs !== null) {
            $startTs = min($startTs, $weTs);
            $endTs = min($endTs, $weTs);
        }
        return max(0, $endTs - $startTs);
    }

    /**
     * Haversine distance using pre-computed radians (returns km)
     */
    private function haversineRad(float $lat1Rad, float $lon1Rad, float $lat2Rad, float $lon2Rad): float
    {
        $dLat = $lat2Rad - $lat1Rad;
        $dLon = $lon2Rad - $lon1Rad;

        $halfDelta = 0.5;
        $a = sin($dLat * $halfDelta) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($dLon * $halfDelta) ** 2;

        return 2 * self::EARTH_RADIUS_KM * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Calculate stoppage on/off duration split
     */
    private function calcStoppageOnOff(int $startIdx, int $endIdx, ?int $wsTs, ?int $weTs): array
    {
        $data = &$this->data;
        $durationOn = 0;
        $durationOff = 0;
        $firstIdx = $startIdx;

        $startTs = $data[$startIdx][self::IDX_TIMESTAMP];

        // Handle stoppage starting before working window
        if ($wsTs !== null && $startTs < $wsTs) {
            for ($i = $startIdx + 1; $i <= $endIdx; $i++) {
                if ($data[$i][self::IDX_TIMESTAMP] >= $wsTs) {
                    $firstIdx = $i;
                    $timeDiff = $this->calcDuration($wsTs, $data[$i][self::IDX_TIMESTAMP], $wsTs, $weTs);
                    if ($data[$startIdx][self::IDX_STATUS] === 1) {
                        $durationOn += $timeDiff;
                    } else {
                        $durationOff += $timeDiff;
                    }
                    break;
                }
            }
            if ($firstIdx === $startIdx) {
                $timeDiff = $this->calcDuration($wsTs, $data[$endIdx][self::IDX_TIMESTAMP], $wsTs, $weTs);
                if ($data[$startIdx][self::IDX_STATUS] === 1) {
                    $durationOn += $timeDiff;
                } else {
                    $durationOff += $timeDiff;
                }
            }
        }

        // Sum duration per segment based on status
        for ($i = $firstIdx + 1; $i <= $endIdx; $i++) {
            $timeDiff = $this->calcDuration($data[$i - 1][self::IDX_TIMESTAMP], $data[$i][self::IDX_TIMESTAMP], $wsTs, $weTs);
            if ($data[$i][self::IDX_STATUS] === 1) {
                $durationOn += $timeDiff;
            } else {
                $durationOff += $timeDiff;
            }
        }

        // Normalize to match total duration
        $totalDuration = $this->calcDuration($data[$startIdx][self::IDX_TIMESTAMP], $data[$endIdx][self::IDX_TIMESTAMP], $wsTs, $weTs);
        $calculated = $durationOn + $durationOff;

        if ($calculated > 0 && $calculated !== $totalDuration) {
            $ratio = $totalDuration / $calculated;
            $durationOn = (int)($durationOn * $ratio);
            $durationOff = $totalDuration - $durationOn;
        }

        return [$durationOn, $durationOff];
    }

    /**
     * Format seconds to HH:MM:SS
     */
    private function formatTime(int $seconds): string
    {
        $hours = intdiv($seconds, self::SECONDS_PER_HOUR);
        $minutes = intdiv($seconds % self::SECONDS_PER_HOUR, 60);
        $secs = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Format unix timestamp to time string HH:MM:SS
     */
    private function formatTimestamp(int $timestamp): string
    {
        return date('H:i:s', $timestamp);
    }

    /**
     * Get empty results structure
     */
    private function getEmptyResults(): array
    {
        return [
            'movement_distance_km' => 0,
            'movement_distance_meters' => 0,
            'movement_duration_seconds' => 0,
            'movement_duration_formatted' => '00:00:00',
            'stoppage_duration_seconds' => 0,
            'stoppage_duration_formatted' => '00:00:00',
            'stoppage_duration_while_on_seconds' => 0,
            'stoppage_duration_while_on_formatted' => '00:00:00',
            'stoppage_duration_while_off_seconds' => 0,
            'stoppage_duration_while_off_formatted' => '00:00:00',
            'stoppage_count' => 0,
            'device_on_time' => null,
            'first_movement_time' => null,
            'latest_status' => 0,
            'average_speed' => 0,
        ];
    }
}
