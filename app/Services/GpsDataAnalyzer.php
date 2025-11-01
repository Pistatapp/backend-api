<?php

namespace App\Services;

class GpsDataAnalyzer
{
    private array $data = [];
    private array $results = [];
    private array $movements = [];
    private array $stoppages = [];

    /**
     * Load GPS data from GpsData model records or array
     * Accepts either a Collection of GpsData models or an array of data
     *
     * @param \Illuminate\Support\Collection|array $data
     * @return self
     */
    public function loadFromRecords($data): self
    {
        $this->data = [];
        $this->movements = [];
        $this->stoppages = [];
        $this->results = [];

        // Convert GpsData models to internal format
        foreach ($data as $record) {
            // Handle both GpsData model instances and arrays
            if (is_object($record)) {
                // GpsData model instance
                $this->data[] = [
                    'latitude' => $record->coordinate[0],
                    'longitude' => $record->coordinate[1],
                    'timestamp' => $record->date_time,
                    'speed' => $record->speed,
                    'status' => $record->status,
                    'imei' => $record->imei,
                ];
            } else {
                // Already in array format
                $this->data[] = $record;
            }
        }

        // Sort by timestamp
        usort($this->data, function ($a, $b) {
            return $a['timestamp']->timestamp <=> $b['timestamp']->timestamp;
        });

        return $this;
    }

    /**
     * Analyze GPS data and calculate all metrics
     * Note: Movement = status == 1 && speed > 0
     * Note: Stoppage = speed == 0
     * Note: Stoppages less than 60 seconds are considered as movements
     * Note: First stoppage point in batch = last movement point
     * Note: First movement point in batch = last stoppage point
     * Note: firstMovementTime is set when 3 consecutive movement points are detected, using the timestamp of the first point
     * Note: stoppage_time calculation:
     *   - For stoppage points: stoppage_time = total duration from start of stoppage segment to end of segment (when movement begins)
     *   - For movement points: stoppage_time = 0 (end of stoppage / start of movement)
     */
    public function analyze(): array
    {
        if (empty($this->data)) {
            return $this->getEmptyResults();
        }

        // Initialize counters
        $movementDistance = 0;
        $movementDuration = 0;
        $stoppageDuration = 0;
        $stoppageDurationWhileOn = 0;
        $stoppageDurationWhileOff = 0;
        $stoppageCount = 0;
        $ignoredStoppageCount = 0;
        $ignoredStoppageDuration = 0;
        $maxSpeed = 0;

        // State tracking
        $previousPoint = null;
        $isCurrentlyStopped = false;
        $isCurrentlyMoving = false;
        $stoppageStartIndex = null;
        $movementStartIndex = null;
        $movementSegmentDistance = 0;

        // Detail indices (for building arrays in single pass)
        $stoppageDetailIndex = 0;
        $ignoredStoppageDetailIndex = 0;
        $movementDetailIndex = 0;

        // Reset detail arrays
        $this->movements = [];
        $this->stoppages = [];

        // Activation tracking
        $deviceOnTime = null;
        $firstMovementTime = null;

        // Track consecutive movements for firstMovementTime detection
        $consecutiveMovementCount = 0;
        $firstConsecutiveMovementIndex = null;

        $dataCount = count($this->data);

        // Helper function to check if point is moving
        $isMovingPoint = function($point) {
            return $point['status'] == 1 && $point['speed'] > 0;
        };

        // Helper function to check if point is stopped
        $isStoppedPoint = function($point) {
            return $point['speed'] == 0;
        };

        // Initialize stoppage_time for all points (will be calculated during iteration)
        foreach ($this->data as $index => $point) {
            $this->data[$index]['stoppage_time'] = 0;
        }

        foreach ($this->data as $index => $currentPoint) {
            // Track max speed
            if ($currentPoint['speed'] > $maxSpeed) {
                $maxSpeed = $currentPoint['speed'];
            }

            // Track activation times inline (optimization: single pass)
            if ($deviceOnTime === null && $currentPoint['status'] == 1) {
                $deviceOnTime = $currentPoint['timestamp']->toTimeString();
            }

            // Track consecutive movements for firstMovementTime
            if ($firstMovementTime === null) {
                if ($isMovingPoint($currentPoint)) {
                    // Start or continue consecutive movement sequence
                    if ($consecutiveMovementCount == 0) {
                        // First point in new sequence
                        $firstConsecutiveMovementIndex = $index;
                    }
                    $consecutiveMovementCount++;

                    // When we reach 3 consecutive movements, set firstMovementTime to the first point
                    if ($consecutiveMovementCount == 3) {
                        $firstMovementTime = $this->data[$firstConsecutiveMovementIndex]['timestamp']->toTimeString();
                    }
                } else {
                    // Non-moving point breaks the sequence
                    $consecutiveMovementCount = 0;
                    $firstConsecutiveMovementIndex = null;
                }
            }

            if ($previousPoint === null) {
                // First point
                if ($isStoppedPoint($currentPoint)) {
                    // First point is stoppage - stoppage_time will be calculated when movement begins or at end
                    $isCurrentlyStopped = true;
                    $stoppageStartIndex = $index;
                } else if ($isMovingPoint($currentPoint)) {
                    // First point is movement - stoppage_time = 0
                    $this->data[$index]['stoppage_time'] = 0;
                    $isCurrentlyMoving = true;
                    $movementStartIndex = $index;
                    $movementSegmentDistance = 0;
                }
                $previousPoint = $currentPoint;
                continue;
            }

            $timeDiff = $currentPoint['timestamp']->timestamp - $previousPoint['timestamp']->timestamp;
            $isStopped = $isStoppedPoint($currentPoint);
            $isMoving = $isMovingPoint($currentPoint);

            // Transition: Moving -> Stopped
            if ($isStopped && $isCurrentlyMoving) {
                // Add transition to movement
                $distance = calculate_distance(
                    [$previousPoint['latitude'], $previousPoint['longitude']],
                    [$currentPoint['latitude'], $currentPoint['longitude']]
                );
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;

                // Save movement detail
                $movementDetailIndex++;
                $duration = $currentPoint['timestamp']->timestamp - $this->data[$movementStartIndex]['timestamp']->timestamp;
                $this->movements[] = [
                    'index' => $movementDetailIndex,
                    'start_time' => $this->data[$movementStartIndex]['timestamp']->toTimeString(),
                    'end_time' => $currentPoint['timestamp']->toTimeString(),
                    'duration_seconds' => $duration,
                    'duration_formatted' => to_time_format($duration),
                    'distance_km' => round($movementSegmentDistance, 3),
                    'distance_meters' => round($movementSegmentDistance * 1000, 2),
                    'start_location' => [
                        'latitude' => $this->data[$movementStartIndex]['latitude'],
                        'longitude' => $this->data[$movementStartIndex]['longitude'],
                    ],
                    'end_location' => [
                        'latitude' => $currentPoint['latitude'],
                        'longitude' => $currentPoint['longitude'],
                    ],
                    'avg_speed' => $duration > 0 ? round(($movementSegmentDistance / $duration) * 3600, 2) : 0,
                ];

                // End of movement / Start of stoppage
                // Previous point (last movement point) has stoppage_time = 0
                $this->data[$index - 1]['stoppage_time'] = 0;

                $isCurrentlyMoving = false;
                $isCurrentlyStopped = true;
                $stoppageStartIndex = $index;
                $movementSegmentDistance = 0;
            }
            // Transition: Stopped -> Moving
            elseif ($isMoving && $isCurrentlyStopped) {
                // Calculate stoppage inline (optimization: no separate method call)
                $tempDuration = 0;
                $tempDurationOn = 0;
                $tempDurationOff = 0;

                for ($i = $stoppageStartIndex + 1; $i <= $index; $i++) {
                    $td = $this->data[$i]['timestamp']->timestamp - $this->data[$i - 1]['timestamp']->timestamp;
                    $tempDuration += $td;
                    if ($this->data[$i]['status'] == 1) {
                        $tempDurationOn += $td;
                    } else {
                        $tempDurationOff += $td;
                    }
                }

                // Calculate stoppage_time for all points in the stoppage segment
                // stoppage_time = total duration from start of stoppage segment to end of segment (when movement begins)
                for ($i = $stoppageStartIndex; $i < $index; $i++) {
                    // Set stoppage_time for each point in the segment (all points get the total segment duration)
                    $this->data[$i]['stoppage_time'] = $tempDuration;
                }

                // End of stoppage / Start of movement
                // Current point (first movement point) has stoppage_time = 0
                $this->data[$index]['stoppage_time'] = 0;

                $isIgnored = $tempDuration < 60;

                // Save stoppage detail
                if ($isIgnored) {
                    $ignoredStoppageDetailIndex++;
                    $displayIndex = "I{$ignoredStoppageDetailIndex}";
                } else {
                    $stoppageDetailIndex++;
                    $displayIndex = $stoppageDetailIndex;
                }

                $this->stoppages[] = [
                    'index' => $displayIndex,
                    'start_time' => $this->data[$stoppageStartIndex]['timestamp']->toTimeString(),
                    'end_time' => $currentPoint['timestamp']->toTimeString(),
                    'duration_seconds' => $tempDuration,
                    'duration_formatted' => to_time_format($tempDuration),
                    'location' => [
                        'latitude' => $this->data[$stoppageStartIndex]['latitude'],
                        'longitude' => $this->data[$stoppageStartIndex]['longitude'],
                    ],
                    'status' => $this->data[$stoppageStartIndex]['status'] == 1 ? 'on' : 'off',
                    'ignored' => $isIgnored,
                ];

                // Update totals
                if ($tempDuration >= 60) {
                    // Stoppage >= 60 seconds: count as actual stoppage
                    $stoppageCount++;
                    $stoppageDuration += $tempDuration;
                    $stoppageDurationWhileOn += $tempDurationOn;
                    $stoppageDurationWhileOff += $tempDurationOff;
                } else {
                    // Stoppage < 60 seconds: treat as movement (add to movement duration)
                    $ignoredStoppageCount++;
                    $ignoredStoppageDuration += $tempDuration;
                    $movementDuration += $tempDuration; // Add short stoppage time to movement duration
                }

                $isCurrentlyStopped = false;
                $isCurrentlyMoving = true;
                $movementStartIndex = $index;
                $movementSegmentDistance = 0;
            }
            // Continue moving
            elseif ($isMoving && $isCurrentlyMoving) {
                // Movement points have stoppage_time = 0
                $this->data[$index]['stoppage_time'] = 0;

                $distance = calculate_distance(
                    [$previousPoint['latitude'], $previousPoint['longitude']],
                    [$currentPoint['latitude'], $currentPoint['longitude']]
                );
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;
            }
            // First stoppage after movement (shouldn't happen with above logic, but safe)
            elseif ($isStopped && !$isCurrentlyStopped && !$isCurrentlyMoving) {
                $isCurrentlyStopped = true;
                $stoppageStartIndex = $index;
            }

            $previousPoint = $currentPoint;
        }

        // Handle final state
        if ($isCurrentlyMoving && $movementStartIndex !== null) {
            // End final movement
            // Ensure last movement point has stoppage_time = 0
            $this->data[$dataCount - 1]['stoppage_time'] = 0;

            $movementDetailIndex++;
            $duration = $previousPoint['timestamp']->timestamp - $this->data[$movementStartIndex]['timestamp']->timestamp;
            $this->movements[] = [
                'index' => $movementDetailIndex,
                'start_time' => $this->data[$movementStartIndex]['timestamp']->toTimeString(),
                'end_time' => $previousPoint['timestamp']->toTimeString(),
                'duration_seconds' => $duration,
                'duration_formatted' => to_time_format($duration),
                'distance_km' => round($movementSegmentDistance, 3),
                'distance_meters' => round($movementSegmentDistance * 1000, 2),
                'start_location' => [
                    'latitude' => $this->data[$movementStartIndex]['latitude'],
                    'longitude' => $this->data[$movementStartIndex]['longitude'],
                ],
                'end_location' => [
                    'latitude' => $previousPoint['latitude'],
                    'longitude' => $previousPoint['longitude'],
                ],
                'avg_speed' => $duration > 0 ? round(($movementSegmentDistance / $duration) * 3600, 2) : 0,
            ];
        } elseif ($isCurrentlyStopped && $stoppageStartIndex !== null) {
            // End final stoppage
            $tempDuration = 0;
            $tempDurationOn = 0;
            $tempDurationOff = 0;

            for ($i = $stoppageStartIndex + 1; $i < $dataCount; $i++) {
                $td = $this->data[$i]['timestamp']->timestamp - $this->data[$i - 1]['timestamp']->timestamp;
                $tempDuration += $td;
                if ($this->data[$i]['status'] == 1) {
                    $tempDurationOn += $td;
                } else {
                    $tempDurationOff += $td;
                }
            }

            // Calculate stoppage_time for all points in the final stoppage segment
            // stoppage_time = cumulative duration from start of stoppage segment to end of data
            for ($i = $stoppageStartIndex; $i < $dataCount; $i++) {
                $this->data[$i]['stoppage_time'] = $tempDuration;
            }

            $isIgnored = $tempDuration < 60;

            if ($isIgnored) {
                $ignoredStoppageDetailIndex++;
                $displayIndex = "I{$ignoredStoppageDetailIndex}";
            } else {
                $stoppageDetailIndex++;
                $displayIndex = $stoppageDetailIndex;
            }

            $this->stoppages[] = [
                'index' => $displayIndex,
                'start_time' => $this->data[$stoppageStartIndex]['timestamp']->toTimeString(),
                'end_time' => $previousPoint['timestamp']->toTimeString(),
                'duration_seconds' => $tempDuration,
                'duration_formatted' => to_time_format($tempDuration),
                'location' => [
                    'latitude' => $this->data[$stoppageStartIndex]['latitude'],
                    'longitude' => $this->data[$stoppageStartIndex]['longitude'],
                ],
                'status' => $this->data[$stoppageStartIndex]['status'] == 1 ? 'on' : 'off',
                'ignored' => $isIgnored,
            ];

            if ($tempDuration >= 60) {
                // Final stoppage >= 60 seconds: count as actual stoppage
                $stoppageCount++;
                $stoppageDuration += $tempDuration;
                $stoppageDurationWhileOn += $tempDurationOn;
                $stoppageDurationWhileOff += $tempDurationOff;
            } else {
                // Final stoppage < 60 seconds: treat as movement (add to movement duration)
                $ignoredStoppageCount++;
                $ignoredStoppageDuration += $tempDuration;
                $movementDuration += $tempDuration; // Add short stoppage time to movement duration
            }
        }

        $averageSpeed = $movementDuration > 0 ? intval($movementDistance * 3600 / $movementDuration) : 0;

        // Calculate total stoppage duration including ignored stoppages
        $totalStoppageDuration = $stoppageDuration + $ignoredStoppageDuration;

        $this->results = [
            'movement_distance_km' => round($movementDistance, 3),
            'movement_distance_meters' => round($movementDistance * 1000, 2),
            'movement_duration_seconds' => $movementDuration,
            'movement_duration_formatted' => to_time_format($movementDuration),
            'stoppage_duration_seconds' => $totalStoppageDuration,
            'stoppage_duration_formatted' => to_time_format($totalStoppageDuration),
            'stoppage_duration_while_on_seconds' => $stoppageDurationWhileOn,
            'stoppage_duration_while_on_formatted' => to_time_format($stoppageDurationWhileOn),
            'stoppage_duration_while_off_seconds' => $stoppageDurationWhileOff,
            'stoppage_duration_while_off_formatted' => to_time_format($stoppageDurationWhileOff),
            'stoppage_count' => $stoppageCount,
            'ignored_stoppage_count' => $ignoredStoppageCount,
            'ignored_stoppage_duration_seconds' => $ignoredStoppageDuration,
            'ignored_stoppage_duration_formatted' => to_time_format($ignoredStoppageDuration),
            'device_on_time' => $deviceOnTime,
            'first_movement_time' => $firstMovementTime,
            'total_records' => $dataCount,
            'start_time' => $this->data[0]['timestamp']->toTimeString(),
            'end_time' => $this->data[$dataCount - 1]['timestamp']->toTimeString(),
            'latest_status' => $this->data[$dataCount - 1]['status'],
            'average_speed' => $averageSpeed,
            'max_speed' => $maxSpeed,
        ];

        return $this->results;
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
            'max_speed' => 0,
        ];
    }

    /**
     * Get detailed stoppage information (optimized - returns cached data)
     * Includes all stoppages with 'ignored' flag for those < 60 seconds
     * Note: Stoppage = status == 0 && speed == 0 || status == 1 && speed == 0
     * Note: First movement point in batch = last stoppage point
     */
    public function getStoppageDetails(): array
    {

        $this->analyze();

        return $this->stoppages;
    }

    /**
     * Get results
     */
    public function getResults(): array
    {
        return $this->results;
    }
}
