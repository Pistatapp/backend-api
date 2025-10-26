<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * GPS Data Analyzer Service
 *
 * Analyzes GPS tracking data to calculate movement metrics, stoppages, and working time.
 * Handles both movement and stoppage detection with configurable working time boundaries.
 *
 * @package App\Services
 */
class GpsDataAnalyzer
{
    // Constants for better maintainability
    private const MIN_STOPPAGE_DURATION_SECONDS = 60;
    private const STATUS_OFF = 0;
    private const STATUS_ON = 1;
    private const MIN_MOVEMENT_SPEED = 2;

    // Core data properties
    private array $data = [];
    private array $results = [];
    private array $movements = [];
    private array $stoppages = [];

    // Working time boundaries
    private ?Carbon $workingStartTime = null;
    private ?Carbon $workingEndTime = null;

    /**
     * Load GPS data from GpsData model records or array
     *
     * Accepts either a Collection of GpsData models or an array of data.
     * Automatically converts model instances to internal format and sorts by timestamp.
     *
     * @param Collection|array $data GPS data records
     * @return self
     * @throws \InvalidArgumentException If data is empty or invalid
     */
    public function loadFromRecords($data): self
    {
        $this->resetState();

        if (empty($data)) {
            throw new \InvalidArgumentException('GPS data cannot be empty');
        }

        $this->data = $this->convertToInternalFormat($data);
        $this->sortDataByTimestamp();

        return $this;
    }

    /**
     * Reset all internal state
     */
    private function resetState(): void
    {
        $this->data = [];
        $this->movements = [];
        $this->stoppages = [];
        $this->results = [];
    }

    /**
     * Convert GPS data to internal format
     *
     * @param Collection|array $data
     * @return array
     */
    private function convertToInternalFormat($data): array
    {
        $convertedData = [];

        foreach ($data as $record) {
            $convertedData[] = $this->convertRecord($record);
        }

        return $convertedData;
    }

    /**
     * Convert a single record to internal format
     *
     * @param mixed $record
     * @return array
     */
    private function convertRecord($record): array
    {
        if (is_object($record)) {
            return $this->convertModelToArray($record);
        }

        return $record;
    }

    /**
     * Convert GpsData model to internal array format
     *
     * @param object $model
     * @return array
     */
    private function convertModelToArray($model): array
    {
        return [
            'latitude' => $model->coordinate[0],
            'longitude' => $model->coordinate[1],
            'timestamp' => $model->date_time,
            'speed' => $model->speed,
            'status' => $model->status,
            'imei' => $model->imei,
        ];
    }

    /**
     * Sort data by timestamp in ascending order
     */
    private function sortDataByTimestamp(): void
    {
        usort($this->data, function ($a, $b) {
            return $a['timestamp']->timestamp <=> $b['timestamp']->timestamp;
        });
    }

    /**
     * Set working time boundaries for filtering GPS data
     *
     * @param Carbon|null $startTime Working start time
     * @param Carbon|null $endTime Working end time
     * @return self
     */
    public function setWorkingTimeBoundaries(?Carbon $startTime, ?Carbon $endTime): self
    {
        $this->workingStartTime = $startTime;
        $this->workingEndTime = $endTime;

        return $this;
    }

    /**
     * Analyze GPS data and calculate all metrics
     *
     * Business Rules:
     * - Stoppage = (status == 0) OR (speed == 0 AND status == 1)
     * - Movement = (status == 1 AND speed > 0)
     * - Stoppages less than 60 seconds are added to movement time
     * - First stoppage point in batch = last movement point
     * - First movement point in batch = last stoppage point
     *
     * @return array Analysis results
     */
    public function analyze(): array
    {
        if (empty($this->data)) {
            return $this->getEmptyResults();
        }

        $this->filterDataByWorkingTime();

        if (empty($this->data)) {
            return $this->getEmptyResults();
        }

        return $this->performAnalysis();
    }

    /**
     * Filter data by working time boundaries if set
     */
    private function filterDataByWorkingTime(): void
    {
        if (!$this->workingStartTime && !$this->workingEndTime) {
            return;
        }

        $this->data = array_filter($this->data, function ($point) {
            return $this->isPointWithinWorkingTime($point);
        });

        // Re-index array after filtering
        $this->data = array_values($this->data);
    }

    /**
     * Check if a data point is within working time boundaries
     *
     * @param array $point
     * @return bool
     */
    private function isPointWithinWorkingTime(array $point): bool
    {
        $pointTime = $point['timestamp'];

        // If working start time is set, filter out points before it
        if ($this->workingStartTime && $pointTime->lt($this->workingStartTime)) {
            return false;
        }

        // If working end time is set, filter out points after it
        if ($this->workingEndTime && $pointTime->gt($this->workingEndTime)) {
            return false;
        }

        return true;
    }

    /**
     * Perform the main analysis of GPS data
     *
     * @return array
     */
    private function performAnalysis(): array
    {
        $analysisState = $this->initializeAnalysisState();

        $this->processDataPoints($analysisState);

        $this->handleFinalState($analysisState);

        return $this->buildResults($analysisState);
    }

    /**
     * Initialize analysis state variables
     *
     * @return array
     */
    private function initializeAnalysisState(): array
    {
        // Reset detail arrays
        $this->movements = [];
        $this->stoppages = [];

        return [
            // Counters
            'movementDistance' => 0,
            'movementDuration' => 0,
            'stoppageDuration' => 0,
            'stoppageDurationWhileOn' => 0,
            'stoppageDurationWhileOff' => 0,
            'stoppageCount' => 0,
            'ignoredStoppageCount' => 0,
            'ignoredStoppageDuration' => 0,

            // State tracking
            'previousPoint' => null,
            'isCurrentlyStopped' => false,
            'isCurrentlyMoving' => false,
            'stoppageStartIndex' => null,
            'movementStartIndex' => null,
            'movementSegmentDistance' => 0,

            // Detail indices
            'stoppageDetailIndex' => 0,
            'ignoredStoppageDetailIndex' => 0,
            'movementDetailIndex' => 0,

            // Activation tracking
            'deviceOnTime' => null,
            'firstMovementTime' => null,

            // Data info
            'dataCount' => count($this->data),
        ];
    }

    /**
     * Process all data points in the GPS data
     *
     * @param array $state Analysis state
     */
    private function processDataPoints(array &$state): void
    {
        foreach ($this->data as $index => $currentPoint) {
            $this->trackActivationTimes($currentPoint, $state);

            if ($state['previousPoint'] === null) {
                $this->handleFirstPoint($currentPoint, $index, $state);
                continue;
            }

            $this->processPointTransition($currentPoint, $index, $state);
        }
    }

    /**
     * Track device activation times
     *
     * @param array $point
     * @param array $state
     */
    private function trackActivationTimes(array $point, array &$state): void
    {
        if ($state['deviceOnTime'] === null && $point['status'] == self::STATUS_ON) {
            $state['deviceOnTime'] = $point['timestamp']->toDateTimeString();
        }

        if ($state['firstMovementTime'] === null &&
            $point['status'] == self::STATUS_ON &&
            $point['speed'] > self::MIN_MOVEMENT_SPEED) {
            $state['firstMovementTime'] = $point['timestamp']->toDateTimeString();
        }
    }

    /**
     * Handle the first data point
     *
     * @param array $point
     * @param int $index
     * @param array $state
     */
    private function handleFirstPoint(array $point, int $index, array &$state): void
    {
        $isStopped = $this->isPointStopped($point);

        if ($isStopped) {
            $state['isCurrentlyStopped'] = true;
            $state['stoppageStartIndex'] = $index;
        } else {
            $state['isCurrentlyMoving'] = true;
            $state['movementStartIndex'] = $index;
            $state['movementSegmentDistance'] = 0;
        }

        $state['previousPoint'] = $point;
    }

    /**
     * Process point transition logic
     *
     * @param array $currentPoint
     * @param int $index
     * @param array $state
     */
    private function processPointTransition(array $currentPoint, int $index, array &$state): void
    {
        $timeDiff = $currentPoint['timestamp']->timestamp - $state['previousPoint']['timestamp']->timestamp;
        $isStopped = $this->isPointStopped($currentPoint);
        $isMoving = $this->isPointMoving($currentPoint);

        if ($isStopped && $state['isCurrentlyMoving']) {
            $this->handleMovementToStoppageTransition($currentPoint, $index, $timeDiff, $state);
        } elseif ($isMoving && $state['isCurrentlyStopped']) {
            $this->handleStoppageToMovementTransition($currentPoint, $index, $state);
        } elseif ($isMoving && $state['isCurrentlyMoving']) {
            $this->continueMovement($currentPoint, $timeDiff, $state);
        } elseif ($isStopped && !$state['isCurrentlyStopped'] && !$state['isCurrentlyMoving']) {
            $state['isCurrentlyStopped'] = true;
            $state['stoppageStartIndex'] = $index;
        }

        $state['previousPoint'] = $currentPoint;
    }

    /**
     * Check if a point represents a stoppage
     *
     * @param array $point
     * @return bool
     */
    private function isPointStopped(array $point): bool
    {
        return ($point['status'] == self::STATUS_OFF) ||
               ($point['speed'] == self::MIN_MOVEMENT_SPEED && $point['status'] == self::STATUS_ON);
    }

    /**
     * Check if a point represents movement
     *
     * @param array $point
     * @return bool
     */
    private function isPointMoving(array $point): bool
    {
        return $point['status'] == self::STATUS_ON && $point['speed'] > self::MIN_MOVEMENT_SPEED;
    }

    /**
     * Handle transition from movement to stoppage
     *
     * @param array $currentPoint
     * @param int $index
     * @param int $timeDiff
     * @param array $state
     */
    private function handleMovementToStoppageTransition(array $currentPoint, int $index, int $timeDiff, array &$state): void
    {
        $distance = $this->calculateDistance($state['previousPoint'], $currentPoint);
        $state['movementSegmentDistance'] += $distance;
        $state['movementDistance'] += $distance;
        $state['movementDuration'] += $timeDiff;

        $this->saveMovementDetail($currentPoint, $state);

        $state['isCurrentlyMoving'] = false;
        $state['isCurrentlyStopped'] = true;
        $state['stoppageStartIndex'] = $index;
        $state['movementSegmentDistance'] = 0;
    }

    /**
     * Handle transition from stoppage to movement
     *
     * @param array $currentPoint
     * @param int $index
     * @param array $state
     */
    private function handleStoppageToMovementTransition(array $currentPoint, int $index, array &$state): void
    {
        $stoppageMetrics = $this->calculateStoppageMetrics($state['stoppageStartIndex'], $index);
        $isIgnored = $stoppageMetrics['duration'] < self::MIN_STOPPAGE_DURATION_SECONDS;

        $this->saveStoppageDetail($currentPoint, $state, $stoppageMetrics, $isIgnored);

        if ($isIgnored) {
            $this->handleIgnoredStoppage($currentPoint, $state, $stoppageMetrics);
        } else {
            $this->handleValidStoppage($state, $stoppageMetrics);
        }

        $state['isCurrentlyStopped'] = false;
        $state['isCurrentlyMoving'] = true;
        $state['movementStartIndex'] = $index;
        $state['movementSegmentDistance'] = 0;
    }

    /**
     * Continue movement processing
     *
     * @param array $currentPoint
     * @param int $timeDiff
     * @param array $state
     */
    private function continueMovement(array $currentPoint, int $timeDiff, array &$state): void
    {
        $distance = $this->calculateDistance($state['previousPoint'], $currentPoint);
        $state['movementSegmentDistance'] += $distance;
        $state['movementDistance'] += $distance;
        $state['movementDuration'] += $timeDiff;
    }

    /**
     * Calculate distance between two points
     *
     * @param array $point1
     * @param array $point2
     * @return float
     */
    private function calculateDistance(array $point1, array $point2): float
    {
        return calculate_distance(
            [$point1['latitude'], $point1['longitude']],
            [$point2['latitude'], $point2['longitude']]
        );
    }

    /**
     * Calculate stoppage metrics
     *
     * @param int $startIndex
     * @param int $endIndex
     * @return array
     */
    private function calculateStoppageMetrics(int $startIndex, int $endIndex): array
    {
        $duration = 0;
        $durationOn = 0;
        $durationOff = 0;

        for ($i = $startIndex + 1; $i <= $endIndex; $i++) {
            $timeDiff = $this->data[$i]['timestamp']->timestamp - $this->data[$i - 1]['timestamp']->timestamp;
            $duration += $timeDiff;

            if ($this->data[$i]['status'] == self::STATUS_ON) {
                $durationOn += $timeDiff;
            } else {
                $durationOff += $timeDiff;
            }
        }

        return [
            'duration' => $duration,
            'durationOn' => $durationOn,
            'durationOff' => $durationOff,
        ];
    }

    /**
     * Save movement detail
     *
     * @param array $currentPoint
     * @param array $state
     */
    private function saveMovementDetail(array $currentPoint, array &$state): void
    {
        $state['movementDetailIndex']++;
        $duration = $currentPoint['timestamp']->timestamp - $this->data[$state['movementStartIndex']]['timestamp']->timestamp;

        $this->movements[] = [
            'index' => $state['movementDetailIndex'],
            'start_time' => $this->data[$state['movementStartIndex']]['timestamp']->toDateTimeString(),
            'end_time' => $currentPoint['timestamp']->toDateTimeString(),
            'duration_seconds' => $duration,
            'duration_formatted' => to_time_format($duration),
            'distance_km' => round($state['movementSegmentDistance'], 3),
            'distance_meters' => round($state['movementSegmentDistance'] * 1000, 2),
            'start_location' => [
                'latitude' => $this->data[$state['movementStartIndex']]['latitude'],
                'longitude' => $this->data[$state['movementStartIndex']]['longitude'],
            ],
            'end_location' => [
                'latitude' => $currentPoint['latitude'],
                'longitude' => $currentPoint['longitude'],
            ],
            'avg_speed' => $duration > 0 ? round(($state['movementSegmentDistance'] / $duration) * 3600, 2) : 0,
        ];
    }

    /**
     * Save stoppage detail
     *
     * @param array $currentPoint
     * @param array $state
     * @param array $metrics
     * @param bool $isIgnored
     */
    private function saveStoppageDetail(array $currentPoint, array &$state, array $metrics, bool $isIgnored): void
    {
        if ($isIgnored) {
            $state['ignoredStoppageDetailIndex']++;
            $displayIndex = "I{$state['ignoredStoppageDetailIndex']}";
        } else {
            $state['stoppageDetailIndex']++;
            $displayIndex = $state['stoppageDetailIndex'];
        }

        $this->stoppages[] = [
            'index' => $displayIndex,
            'start_time' => $this->data[$state['stoppageStartIndex']]['timestamp']->toDateTimeString(),
            'end_time' => $currentPoint['timestamp']->toDateTimeString(),
            'duration_seconds' => $metrics['duration'],
            'duration_formatted' => to_time_format($metrics['duration']),
            'location' => [
                'latitude' => $this->data[$state['stoppageStartIndex']]['latitude'],
                'longitude' => $this->data[$state['stoppageStartIndex']]['longitude'],
            ],
            'status' => $this->data[$state['stoppageStartIndex']]['status'] == self::STATUS_ON ? 'on' : 'off',
            'ignored' => $isIgnored,
        ];
    }

    /**
     * Handle ignored stoppage (less than 60 seconds)
     *
     * @param array $currentPoint
     * @param array $state
     * @param array $metrics
     */
    private function handleIgnoredStoppage(array $currentPoint, array &$state, array $metrics): void
    {
        $state['ignoredStoppageCount']++;
        $state['ignoredStoppageDuration'] += $metrics['duration'];
        $state['movementDuration'] += $metrics['duration'];

        // Add distance for ignored stoppages to movement distance
        $ignoredStoppageDistance = $this->calculateDistance(
            [$this->data[$state['stoppageStartIndex']]['latitude'], $this->data[$state['stoppageStartIndex']]['longitude']],
            [$currentPoint['latitude'], $currentPoint['longitude']]
        );
        $state['movementDistance'] += $ignoredStoppageDistance;
    }

    /**
     * Handle valid stoppage (60 seconds or more)
     *
     * @param array $state
     * @param array $metrics
     */
    private function handleValidStoppage(array &$state, array $metrics): void
    {
        $state['stoppageCount']++;
        $state['stoppageDuration'] += $metrics['duration'];
        $state['stoppageDurationWhileOn'] += $metrics['durationOn'];
        $state['stoppageDurationWhileOff'] += $metrics['durationOff'];
    }

    /**
     * Handle final state after processing all points
     *
     * @param array $state
     */
    private function handleFinalState(array &$state): void
    {
        if ($state['isCurrentlyMoving'] && $state['movementStartIndex'] !== null) {
            $this->handleFinalMovement($state);
        } elseif ($state['isCurrentlyStopped'] && $state['stoppageStartIndex'] !== null) {
            $this->handleFinalStoppage($state);
        }
    }

    /**
     * Handle final movement
     *
     * @param array $state
     */
    private function handleFinalMovement(array &$state): void
    {
        $state['movementDetailIndex']++;
        $duration = $state['previousPoint']['timestamp']->timestamp - $this->data[$state['movementStartIndex']]['timestamp']->timestamp;

        $this->movements[] = [
            'index' => $state['movementDetailIndex'],
            'start_time' => $this->data[$state['movementStartIndex']]['timestamp']->toDateTimeString(),
            'end_time' => $state['previousPoint']['timestamp']->toDateTimeString(),
            'duration_seconds' => $duration,
            'duration_formatted' => to_time_format($duration),
            'distance_km' => round($state['movementSegmentDistance'], 3),
            'distance_meters' => round($state['movementSegmentDistance'] * 1000, 2),
            'start_location' => [
                'latitude' => $this->data[$state['movementStartIndex']]['latitude'],
                'longitude' => $this->data[$state['movementStartIndex']]['longitude'],
            ],
            'end_location' => [
                'latitude' => $state['previousPoint']['latitude'],
                'longitude' => $state['previousPoint']['longitude'],
            ],
            'avg_speed' => $duration > 0 ? round(($state['movementSegmentDistance'] / $duration) * 3600, 2) : 0,
        ];
    }

    /**
     * Handle final stoppage
     *
     * @param array $state
     */
    private function handleFinalStoppage(array &$state): void
    {
        $metrics = $this->calculateFinalStoppageMetrics($state);
        $isIgnored = $metrics['duration'] < self::MIN_STOPPAGE_DURATION_SECONDS;

        $this->saveFinalStoppageDetail($state, $metrics, $isIgnored);

        if ($isIgnored) {
            $this->handleFinalIgnoredStoppage($state, $metrics);
        } else {
            $this->handleFinalValidStoppage($state, $metrics);
        }
    }

    /**
     * Calculate final stoppage metrics
     *
     * @param array $state
     * @return array
     */
    private function calculateFinalStoppageMetrics(array $state): array
    {
        $duration = 0;
        $durationOn = 0;
        $durationOff = 0;

        for ($i = $state['stoppageStartIndex'] + 1; $i < $state['dataCount']; $i++) {
            $timeDiff = $this->data[$i]['timestamp']->timestamp - $this->data[$i - 1]['timestamp']->timestamp;
            $duration += $timeDiff;

            if ($this->data[$i]['status'] == self::STATUS_ON) {
                $durationOn += $timeDiff;
            } else {
                $durationOff += $timeDiff;
            }
        }

        return [
            'duration' => $duration,
            'durationOn' => $durationOn,
            'durationOff' => $durationOff,
        ];
    }

    /**
     * Save final stoppage detail
     *
     * @param array $state
     * @param array $metrics
     * @param bool $isIgnored
     */
    private function saveFinalStoppageDetail(array &$state, array $metrics, bool $isIgnored): void
    {
        if ($isIgnored) {
            $state['ignoredStoppageDetailIndex']++;
            $displayIndex = "I{$state['ignoredStoppageDetailIndex']}";
        } else {
            $state['stoppageDetailIndex']++;
            $displayIndex = $state['stoppageDetailIndex'];
        }

        $this->stoppages[] = [
            'index' => $displayIndex,
            'start_time' => $this->data[$state['stoppageStartIndex']]['timestamp']->toDateTimeString(),
            'end_time' => $state['previousPoint']['timestamp']->toDateTimeString(),
            'duration_seconds' => $metrics['duration'],
            'duration_formatted' => to_time_format($metrics['duration']),
            'location' => [
                'latitude' => $this->data[$state['stoppageStartIndex']]['latitude'],
                'longitude' => $this->data[$state['stoppageStartIndex']]['longitude'],
            ],
            'status' => $this->data[$state['stoppageStartIndex']]['status'] == self::STATUS_ON ? 'on' : 'off',
            'ignored' => $isIgnored,
        ];
    }

    /**
     * Handle final ignored stoppage
     *
     * @param array $state
     * @param array $metrics
     */
    private function handleFinalIgnoredStoppage(array &$state, array $metrics): void
    {
        $state['ignoredStoppageCount']++;
        $state['ignoredStoppageDuration'] += $metrics['duration'];
        $state['movementDuration'] += $metrics['duration'];

        // Add distance for ignored stoppages to movement distance
        $ignoredStoppageDistance = $this->calculateDistance(
            [$this->data[$state['stoppageStartIndex']]['latitude'], $this->data[$state['stoppageStartIndex']]['longitude']],
            [$state['previousPoint']['latitude'], $state['previousPoint']['longitude']]
        );
        $state['movementDistance'] += $ignoredStoppageDistance;
    }

    /**
     * Handle final valid stoppage
     *
     * @param array $state
     * @param array $metrics
     */
    private function handleFinalValidStoppage(array &$state, array $metrics): void
    {
        $state['stoppageCount']++;
        $state['stoppageDuration'] += $metrics['duration'];
        $state['stoppageDurationWhileOn'] += $metrics['durationOn'];
        $state['stoppageDurationWhileOff'] += $metrics['durationOff'];
    }

    /**
     * Build final results array
     *
     * @param array $state
     * @return array
     */
    private function buildResults(array $state): array
    {
        $averageSpeed = $state['movementDuration'] > 0 ?
            intval($state['movementDistance'] / $state['movementDuration']) : 0;

        // Calculate total stoppage duration including ignored stoppages
        $totalStoppageDuration = $state['stoppageDuration'] + $state['ignoredStoppageDuration'];

        $this->results = [
            'movement_distance_km' => round($state['movementDistance'], 3),
            'movement_distance_meters' => round($state['movementDistance'] * 1000, 2),
            'movement_duration_seconds' => $state['movementDuration'],
            'movement_duration_formatted' => to_time_format($state['movementDuration']),
            'stoppage_duration_seconds' => $totalStoppageDuration,
            'stoppage_duration_formatted' => to_time_format($totalStoppageDuration),
            'stoppage_duration_while_on_seconds' => $state['stoppageDurationWhileOn'],
            'stoppage_duration_while_on_formatted' => to_time_format($state['stoppageDurationWhileOn']),
            'stoppage_duration_while_off_seconds' => $state['stoppageDurationWhileOff'],
            'stoppage_duration_while_off_formatted' => to_time_format($state['stoppageDurationWhileOff']),
            'stoppage_count' => $state['stoppageCount'],
            'ignored_stoppage_count' => $state['ignoredStoppageCount'],
            'ignored_stoppage_duration_seconds' => $state['ignoredStoppageDuration'],
            'ignored_stoppage_duration_formatted' => to_time_format($state['ignoredStoppageDuration']),
            'device_on_time' => $state['deviceOnTime'],
            'first_movement_time' => $state['firstMovementTime'],
            'total_records' => $state['dataCount'],
            'start_time' => $this->data[0]['timestamp']->toDateTimeString(),
            'end_time' => $this->data[$state['dataCount'] - 1]['timestamp']->toDateTimeString(),
            'latest_status' => $this->data[$state['dataCount'] - 1]['status'],
            'average_speed' => $averageSpeed,
        ];

        return $this->results;
    }

    /**
     * Get empty results structure for when no data is available
     *
     * @return array
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
            'ignored_stoppage_count' => 0,
            'ignored_stoppage_duration_seconds' => 0,
            'ignored_stoppage_duration_formatted' => '00:00:00',
            'device_on_time' => null,
            'first_movement_time' => null,
            'total_records' => 0,
            'start_time' => null,
            'end_time' => null,
            'latest_status' => null,
            'average_speed' => 0,
        ];
    }

    /**
     * Get detailed stoppage information
     *
     * Returns all stoppages with 'ignored' flag for those less than 60 seconds.
     * Automatically runs analysis if not already performed.
     *
     * Business Rules:
     * - Stoppage = (status == 0) OR (speed == 0 AND status == 1)
     * - First movement point in batch = last stoppage point
     *
     * @return array Detailed stoppage information
     */
    public function getStoppageDetails(): array
    {
        if (empty($this->results)) {
            $this->analyze();
        }

        return $this->stoppages;
    }

    /**
     * Get detailed movement information
     *
     * Returns all movement segments with distance, duration, and speed metrics.
     * Automatically runs analysis if not already performed.
     *
     * @return array Detailed movement information
     */
    public function getMovementDetails(): array
    {
        if (empty($this->results)) {
            $this->analyze();
        }

        return $this->movements;
    }

    /**
     * Get analysis results
     *
     * Returns the main analysis results including summary metrics.
     * Automatically runs analysis if not already performed.
     *
     * @return array Analysis results
     */
    public function getResults(): array
    {
        if (empty($this->results)) {
            $this->analyze();
        }

        return $this->results;
    }
}
