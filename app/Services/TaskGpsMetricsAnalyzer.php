<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Tractor;

/**
 * GPS Metrics Analyzer for Task Zones
 *
 * This analyzer calculates GPS metrics specifically for tractor tasks within designated zones.
 * Unlike GpsDataAnalyzer which continuously analyzes all data, this analyzer segments data
 * by zone presence and only measures metrics while inside the task zone.
 *
 * Key behaviors:
 * - Metrics (movement/stoppage) are only measured when tractor is inside task zone
 * - When tractor exits zone, measurement stops
 * - When tractor re-enters zone, measurement resumes in a new segment
 * - Gaps between segments (exit to re-entry) are completely ignored (no time, no distance)
 */
class TaskGpsMetricsAnalyzer
{
    private array $data = [];
    private array $results = [];

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
    public function loadRecordsFor(Tractor $tractor, Carbon $taskStartTime, Carbon $taskEndTime): self
    {
        $gpsData = $tractor->gpsData()
            ->whereBetween('gps_data.date_time', [$taskStartTime, $taskEndTime])
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
            $lat = $coordinate[0] ?? null;
            $lon = $coordinate[1] ?? null;
            if ($lat === null || $lon === null) {
                return null;
            }
            return [(float)$lat, (float)$lon];
        }

        if (is_string($coordinate)) {
            // Try JSON decode first
            $decoded = json_decode($coordinate, true);
            if (is_array($decoded) && count($decoded) >= self::MIN_COORDINATE_COUNT) {
                $lat = $decoded[0] ?? null;
                $lon = $decoded[1] ?? null;
                if ($lat === null || $lon === null) {
                    return null;
                }
                return [(float)$lat, (float)$lon];
            }

            // Fall back to comma-separated format
            $parts = explode(',', $coordinate);
            if (count($parts) >= self::MIN_COORDINATE_COUNT) {
                $lat = trim($parts[0]);
                $lon = trim($parts[1]);
                if ($lat === '' || $lon === '') {
                    return null;
                }
                return [(float)$lat, (float)$lon];
            }
        }

        return null;
    }

    /**
     * Analyze GPS data within task zone and calculate metrics
     *
     * This is the main entry point. It segments the data by zone presence
     * and analyzes each segment independently.
     *
     * @param array $polygon Task zone polygon coordinates
     * @return array Analysis results
     */
    public function analyze(array $polygon = []): array
    {
        $dataCount = count($this->data);

        if ($dataCount === 0 || empty($polygon)) {
            return $this->getEmptyResults();
        }

        // Identify continuous segments where tractor is inside the zone
        $segments = $this->identifyZoneSegments($polygon);

        if (empty($segments)) {
            return $this->getEmptyResults();
        }

        // Analyze each segment and merge results
        return $this->analyzeSegments($segments);
    }

    /**
     * Identify continuous presence segments within the task zone
     *
     * A segment is a continuous period where the tractor is inside the zone.
     * Returns array of segments, each containing start and end indices.
     *
     * @param array $polygon Task zone polygon
     * @return array Array of segments [['start' => idx, 'end' => idx], ...]
     */
    private function identifyZoneSegments(array $polygon): array
    {
        $segments = [];
        $inZone = false;
        $segmentStart = -1;
        $dataCount = count($this->data);

        for ($i = 0; $i < $dataCount; $i++) {
            $point = $this->data[$i];
            $pointInZone = is_point_in_polygon(
                [$point[self::IDX_LAT], $point[self::IDX_LON]],
                $polygon
            );

            if ($pointInZone && !$inZone) {
                // Entering zone - start new segment
                $segmentStart = $i;
                $inZone = true;
            } elseif (!$pointInZone && $inZone) {
                // Exiting zone - close current segment
                $segments[] = [
                    'start' => $segmentStart,
                    'end' => $i - 1, // Last point that was in zone
                ];
                $inZone = false;
                $segmentStart = -1;
            }
        }

        // If still in zone at end of data, include partial segment
        if ($inZone && $segmentStart >= 0) {
            $segments[] = [
                'start' => $segmentStart,
                'end' => $dataCount - 1,
            ];
        }

        return $segments;
    }

    /**
     * Analyze all segments and merge their metrics
     *
     * @param array $segments Array of segment definitions
     * @return array Combined metrics from all segments
     */
    private function analyzeSegments(array $segments): array
    {
        $totalMetrics = $this->initializeMetrics();
        $globalActivation = $this->initializeActivationTracking();
        $lastStatus = 0;

        foreach ($segments as $segment) {
            $segmentResults = $this->analyzeSegment(
                $segment['start'],
                $segment['end'],
                $globalActivation
            );

            // Merge metrics
            $totalMetrics['movement_distance'] += $segmentResults['metrics']['movement_distance'];
            $totalMetrics['movement_duration'] += $segmentResults['metrics']['movement_duration'];
            $totalMetrics['stoppage_duration'] += $segmentResults['metrics']['stoppage_duration'];
            $totalMetrics['stoppage_duration_while_on'] += $segmentResults['metrics']['stoppage_duration_while_on'];
            $totalMetrics['stoppage_duration_while_off'] += $segmentResults['metrics']['stoppage_duration_while_off'];
            $totalMetrics['stoppage_count'] += $segmentResults['metrics']['stoppage_count'];

            // Update global activation tracking
            if ($globalActivation['device_on_timestamp'] === null && $segmentResults['activation']['device_on_timestamp'] !== null) {
                $globalActivation['device_on_timestamp'] = $segmentResults['activation']['device_on_timestamp'];
            }
            if ($globalActivation['first_movement_timestamp'] === null && $segmentResults['activation']['first_movement_timestamp'] !== null) {
                $globalActivation['first_movement_timestamp'] = $segmentResults['activation']['first_movement_timestamp'];
            }

            // Track latest status from last segment
            $lastStatus = $this->data[$segment['end']][self::IDX_STATUS];
        }

        return $this->buildResults($totalMetrics, $globalActivation, $lastStatus);
    }

    /**
     * Analyze a single segment of zone presence
     *
     * This processes GPS points within one continuous presence in the zone,
     * calculating movement and stoppage metrics just like GpsDataAnalyzer
     * but only for points within this segment.
     *
     * @param int $startIdx Index of first point in segment
     * @param int $endIdx Index of last point in segment
     * @param array $globalActivation Global activation tracking (for first movement across all segments)
     * @return array Segment metrics and activation data
     */
    private function analyzeSegment(int $startIdx, int $endIdx, array &$globalActivation): array
    {
        $metrics = $this->initializeMetrics();
        $state = $this->initializeState();
        $activation = $this->initializeActivationTracking();

        $previousPoint = null;

        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $point = &$this->data[$i];
            $pointData = $this->extractPointData($point, $i);

            // Track device activation
            $activation = $this->trackDeviceActivation($activation, $pointData);

            // Track first movement (within this segment and globally)
            $activation = $this->trackFirstMovement($activation, $pointData, $i);
            $globalActivation = $this->trackFirstMovement($globalActivation, $pointData, $i);

            if ($previousPoint === null) {
                $previousPoint = $this->initializeFirstPoint($pointData, $state);
                continue;
            }

            $timeDiff = $pointData['timestamp'] - $previousPoint['timestamp'];

            $this->processStateTransition(
                $pointData,
                $previousPoint,
                $state,
                $metrics,
                $timeDiff,
                $i
            );

            $previousPoint = $pointData;
        }

        // Handle final state for this segment
        $this->handleFinalState($state, $metrics, $startIdx, $endIdx);

        return [
            'metrics' => $metrics,
            'activation' => $activation,
        ];
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
            $this->handleMovingToStopped($pointData, $state, $metrics, $distance, $timeDiff);
        } elseif ($pointData['is_moving'] && $state['is_stopped']) {
            // Stopped -> Moving
            $this->handleStoppedToMoving($pointData, $state, $metrics, $distance, $currentIndex);
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
        array &$state,
        array &$metrics,
        float $distance,
        int $currentIndex
    ): void {
        $stoppageStartTs = $this->data[$state['stoppage_start_index']][self::IDX_TIMESTAMP];
        $stoppageDuration = $pointData['timestamp'] - $stoppageStartTs;

        if ($stoppageDuration >= self::MIN_STOPPAGE_DURATION_SECONDS) {
            $metrics['stoppage_count']++;
            $metrics['stoppage_duration'] += $stoppageDuration;
            [$onDuration, $offDuration] = $this->calcStoppageOnOff($state['stoppage_start_index'], $currentIndex);
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
     * Handle final state after processing segment points
     *
     * @param array $state Current state
     * @param array $metrics Metrics array (modified by reference)
     * @param int $startIdx Segment start index
     * @param int $endIdx Segment end index
     */
    private function handleFinalState(
        array $state,
        array &$metrics,
        int $startIdx,
        int $endIdx
    ): void {
        if (!$state['is_stopped'] || $state['stoppage_start_index'] < 0) {
            return;
        }

        $stoppageStartTs = $this->data[$state['stoppage_start_index']][self::IDX_TIMESTAMP];
        $lastTs = $this->data[$endIdx][self::IDX_TIMESTAMP];
        $stoppageDuration = $lastTs - $stoppageStartTs;

        if ($stoppageDuration >= self::MIN_STOPPAGE_DURATION_SECONDS) {
            $metrics['stoppage_count']++;
            $metrics['stoppage_duration'] += $stoppageDuration;
            [$onDuration, $offDuration] = $this->calcStoppageOnOff($state['stoppage_start_index'], $endIdx);
            $metrics['stoppage_duration_while_on'] += $onDuration;
            $metrics['stoppage_duration_while_off'] += $offDuration;
        } else {
            $metrics['movement_duration'] += $stoppageDuration;
        }
    }

    /**
     * Build final results array from metrics and activation data
     *
     * @param array $metrics Combined metrics from all segments
     * @param array $activation Global activation tracking
     * @param int $lastStatus Status from last point in last segment
     * @return array Final results
     */
    private function buildResults(array $metrics, array $activation, int $lastStatus): array
    {
        $averageSpeed = $metrics['movement_duration'] > 0
            ? (int)($metrics['movement_distance'] * self::SECONDS_PER_HOUR / $metrics['movement_duration'])
            : 0;

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
            'latest_status' => $lastStatus,
            'average_speed' => $averageSpeed,
        ];

        return $this->results;
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
     *
     * @param int $startIdx Stoppage start index
     * @param int $endIdx Stoppage end index
     * @return array [onDuration, offDuration]
     */
    private function calcStoppageOnOff(int $startIdx, int $endIdx): array
    {
        $data = &$this->data;
        $durationOn = 0;
        $durationOff = 0;

        // Sum duration per segment based on status
        for ($i = $startIdx + 1; $i <= $endIdx; $i++) {
            $timeDiff = $data[$i][self::IDX_TIMESTAMP] - $data[$i - 1][self::IDX_TIMESTAMP];
            if ($data[$i][self::IDX_STATUS] === 1) {
                $durationOn += $timeDiff;
            } else {
                $durationOff += $timeDiff;
            }
        }

        // Normalize to match total duration
        $totalDuration = $data[$endIdx][self::IDX_TIMESTAMP] - $data[$startIdx][self::IDX_TIMESTAMP];
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

