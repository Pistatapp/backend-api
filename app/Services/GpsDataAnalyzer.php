<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Tractor;

class GpsDataAnalyzer
{
    private array $data = [];
    private array $results = [];
    private array $movements = [];
    private array $stoppages = [];
    private ?Carbon $workingStartTime = null;
    private ?Carbon $workingEndTime = null;

    /**
     * Load GPS records for a tractor on a specific date and set working time window
     * This method fetches GPS data and automatically configures working time from tractor settings
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return self
     */
    public function loadRecordsFor(Tractor $tractor, Carbon $date): self
    {
        // Fetch GPS data for the tractor on the specified date
        $gpsData = $tractor->gpsData()->whereDate('date_time', $date)->get();

        // Load records
        $this->loadFromRecords($gpsData);

        // Get and form tractor working time window
        if ($tractor->start_work_time && $tractor->end_work_time) {
            // Create Carbon instances with the date and time from tractor settings
            $this->workingStartTime = $date->copy()->setTimeFromTimeString($tractor->start_work_time);
            $this->workingEndTime = $date->copy()->setTimeFromTimeString($tractor->end_work_time);

            // Handle case where end time is before start time (crosses midnight)
            if ($this->workingEndTime->lt($this->workingStartTime)) {
                $this->workingEndTime->addDay();
            }
        }

        return $this;
    }

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
        // Reset working time when loading records directly (will be set by loadRecordsFor if needed)
        $this->workingStartTime = null;
        $this->workingEndTime = null;

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
     * Note: Stoppages less than 60 seconds are considered as movements and are NOT included in stoppages array
     * Note: Only stoppages >= 60 seconds are counted and included in stoppages details (matches TractorPathService behavior)
     * Note: First stoppage point in batch = last movement point
     * Note: First movement point in batch = last stoppage point
     * Note: firstMovementTime is set when 3 consecutive movement points are detected, using the timestamp of the first point
     * Note: stoppage_time calculation:
     *   - For stoppage points: stoppage_time = total duration from start of stoppage segment to end of segment (when movement begins)
     *   - For movement points: stoppage_time = 0 (end of stoppage / start of movement)
     * Note: Working time scope:
     *   - If workingStartTime is provided, calculations start from that time even if points exist before it
     *   - If workingEndTime is provided, calculations end at that time even if points exist after it
     *   - Stoppages that start before workingStartTime count only from workingStartTime
     *   - Stoppages/movements that extend beyond workingEndTime count only until workingEndTime
     *
     * @param Carbon|null $workingStartTime Optional working start time to scope calculations
     * @param Carbon|null $workingEndTime Optional working end time to scope calculations
     * @return array
     */
    public function analyze(?Carbon $workingStartTime = null, ?Carbon $workingEndTime = null): array
    {
        if (empty($this->data)) {
            return $this->getEmptyResults();
        }

        // Use stored working time if parameters are not provided
        if ($workingStartTime === null && $this->workingStartTime !== null) {
            $workingStartTime = $this->workingStartTime;
        }
        if ($workingEndTime === null && $this->workingEndTime !== null) {
            $workingEndTime = $this->workingEndTime;
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

            // Calculate time difference within working time boundaries
            $timeDiff = $this->calculateDurationWithinWorkingTime(
                $previousPoint['timestamp'],
                $currentPoint['timestamp'],
                $workingStartTime,
                $workingEndTime
            );
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
                $effectiveMovementStart = $this->getEffectiveStartTime(
                    $this->data[$movementStartIndex]['timestamp'],
                    $workingStartTime
                );
                $effectiveMovementEnd = $this->getEffectiveEndTime(
                    $currentPoint['timestamp'],
                    $workingEndTime
                );
                $duration = $this->calculateDurationWithinWorkingTime(
                    $this->data[$movementStartIndex]['timestamp'],
                    $currentPoint['timestamp'],
                    $workingStartTime,
                    $workingEndTime
                );
                $this->movements[] = [
                    'index' => $movementDetailIndex,
                    'start_time' => $effectiveMovementStart->toTimeString(),
                    'end_time' => $effectiveMovementEnd->toTimeString(),
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
                // Calculate stoppage inline with working time boundaries
                // Get effective start and end times for the stoppage segment
                $stoppageStartTimestamp = $this->data[$stoppageStartIndex]['timestamp'];
                $stoppageEndTimestamp = $currentPoint['timestamp'];

                // Calculate total duration within working time boundaries
                $tempDuration = $this->calculateDurationWithinWorkingTime(
                    $stoppageStartTimestamp,
                    $stoppageEndTimestamp,
                    $workingStartTime,
                    $workingEndTime
                );

                // Calculate on/off durations by iterating through points within working time
                $tempDurationOn = 0;
                $tempDurationOff = 0;

                // Determine the first point index to consider (within working time)
                $firstPointIndex = $stoppageStartIndex;
                if ($workingStartTime && $stoppageStartTimestamp->lt($workingStartTime)) {
                    // Stoppage starts before working time - add duration from workingStartTime to first point after it
                    // Use status from stoppageStartIndex point for this duration
                    for ($i = $stoppageStartIndex + 1; $i <= $index; $i++) {
                        if ($this->data[$i]['timestamp']->gte($workingStartTime)) {
                            $firstPointIndex = $i;
                            $td = $this->calculateDurationWithinWorkingTime(
                                $workingStartTime,
                                $this->data[$i]['timestamp'],
                                $workingStartTime,
                                $workingEndTime
                            );
                            // Use status from stoppageStartIndex point for this interval (from workingStartTime to first point)
                            if ($this->data[$stoppageStartIndex]['status'] == 1) {
                                $tempDurationOn += $td;
                            } else {
                                $tempDurationOff += $td;
                            }
                            break;
                        }
                    }
                    // If no point found after workingStartTime, use stoppageStartIndex status for remaining duration
                    if ($firstPointIndex == $stoppageStartIndex) {
                        $td = $this->calculateDurationWithinWorkingTime(
                            $workingStartTime,
                            $stoppageEndTimestamp,
                            $workingStartTime,
                            $workingEndTime
                        );
                        if ($this->data[$stoppageStartIndex]['status'] == 1) {
                            $tempDurationOn += $td;
                        } else {
                            $tempDurationOff += $td;
                        }
                    }
                }

                // Iterate through remaining points
                for ($i = $firstPointIndex + 1; $i <= $index; $i++) {
                    $td = $this->calculateDurationWithinWorkingTime(
                        $this->data[$i - 1]['timestamp'],
                        $this->data[$i]['timestamp'],
                        $workingStartTime,
                        $workingEndTime
                    );
                    if ($this->data[$i]['status'] == 1) {
                        $tempDurationOn += $td;
                    } else {
                        $tempDurationOff += $td;
                    }
                }

                // If stoppage extends beyond workingEndTime, we've already clamped it in calculateDurationWithinWorkingTime
                // But we need to make sure on/off split is proportional to the clamped duration
                // If tempDuration is less than the sum of on+off, adjust proportionally
                $totalCalculatedDuration = $tempDurationOn + $tempDurationOff;
                if ($totalCalculatedDuration > 0 && $totalCalculatedDuration != $tempDuration) {
                    // Adjust proportionally to match the clamped duration
                    $ratio = $tempDuration / $totalCalculatedDuration;
                    $tempDurationOn = intval($tempDurationOn * $ratio);
                    $tempDurationOff = $tempDuration - $tempDurationOn;
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

                // Only save stoppage detail if duration >= 60 seconds (match TractorPathService behavior)
                if (!$isIgnored) {
                    $stoppageDetailIndex++;
                    $displayIndex = $stoppageDetailIndex;

                    $effectiveStoppageStart = $this->getEffectiveStartTime(
                        $this->data[$stoppageStartIndex]['timestamp'],
                        $workingStartTime
                    );
                    $effectiveStoppageEnd = $this->getEffectiveEndTime(
                        $currentPoint['timestamp'],
                        $workingEndTime
                    );

                    $this->stoppages[] = [
                        'index' => $displayIndex,
                        'start_time' => $effectiveStoppageStart->toTimeString(),
                        'end_time' => $effectiveStoppageEnd->toTimeString(),
                        'duration_seconds' => $tempDuration,
                        'duration_formatted' => to_time_format($tempDuration),
                        'location' => [
                            'latitude' => $this->data[$stoppageStartIndex]['latitude'],
                            'longitude' => $this->data[$stoppageStartIndex]['longitude'],
                        ],
                        'status' => $this->data[$stoppageStartIndex]['status'] == 1 ? 'on' : 'off',
                        'ignored' => false,
                    ];
                } else {
                    $ignoredStoppageDetailIndex++;
                }

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

                // Only count movement if both points are within working time or the segment crosses working time
                // Distance is always counted, but duration only within working time
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
            $effectiveMovementStart = $this->getEffectiveStartTime(
                $this->data[$movementStartIndex]['timestamp'],
                $workingStartTime
            );
            $effectiveMovementEnd = $this->getEffectiveEndTime(
                $previousPoint['timestamp'],
                $workingEndTime
            );
            $duration = $this->calculateDurationWithinWorkingTime(
                $this->data[$movementStartIndex]['timestamp'],
                $previousPoint['timestamp'],
                $workingStartTime,
                $workingEndTime
            );
            $this->movements[] = [
                'index' => $movementDetailIndex,
                'start_time' => $effectiveMovementStart->toTimeString(),
                'end_time' => $effectiveMovementEnd->toTimeString(),
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
            $stoppageStartTimestamp = $this->data[$stoppageStartIndex]['timestamp'];
            $stoppageEndTimestamp = $previousPoint['timestamp'];

            // Calculate total duration within working time boundaries
            $tempDuration = $this->calculateDurationWithinWorkingTime(
                $stoppageStartTimestamp,
                $stoppageEndTimestamp,
                $workingStartTime,
                $workingEndTime
            );

            // Calculate on/off durations by iterating through points within working time
            $tempDurationOn = 0;
            $tempDurationOff = 0;

            // Determine the first point index to consider (within working time)
            $firstPointIndex = $stoppageStartIndex;
            if ($workingStartTime && $stoppageStartTimestamp->lt($workingStartTime)) {
                // Stoppage starts before working time - add duration from workingStartTime to first point after it
                for ($i = $stoppageStartIndex + 1; $i < $dataCount; $i++) {
                    if ($this->data[$i]['timestamp']->gte($workingStartTime)) {
                        $firstPointIndex = $i;
                        $td = $this->calculateDurationWithinWorkingTime(
                            $workingStartTime,
                            $this->data[$i]['timestamp'],
                            $workingStartTime,
                            $workingEndTime
                        );
                        // Use status from stoppageStartIndex point for this interval
                        if ($this->data[$stoppageStartIndex]['status'] == 1) {
                            $tempDurationOn += $td;
                        } else {
                            $tempDurationOff += $td;
                        }
                        break;
                    }
                }
                // If no point found after workingStartTime, use stoppageStartIndex status for remaining duration
                if ($firstPointIndex == $stoppageStartIndex) {
                    $td = $this->calculateDurationWithinWorkingTime(
                        $workingStartTime,
                        $stoppageEndTimestamp,
                        $workingStartTime,
                        $workingEndTime
                    );
                    if ($this->data[$stoppageStartIndex]['status'] == 1) {
                        $tempDurationOn += $td;
                    } else {
                        $tempDurationOff += $td;
                    }
                }
            }

            // Iterate through remaining points
            for ($i = $firstPointIndex + 1; $i < $dataCount; $i++) {
                $td = $this->calculateDurationWithinWorkingTime(
                    $this->data[$i - 1]['timestamp'],
                    $this->data[$i]['timestamp'],
                    $workingStartTime,
                    $workingEndTime
                );
                if ($this->data[$i]['status'] == 1) {
                    $tempDurationOn += $td;
                } else {
                    $tempDurationOff += $td;
                }
            }

            // If stoppage extends beyond workingEndTime, adjust on/off split proportionally
            $totalCalculatedDuration = $tempDurationOn + $tempDurationOff;
            if ($totalCalculatedDuration > 0 && $totalCalculatedDuration != $tempDuration) {
                $ratio = $tempDuration / $totalCalculatedDuration;
                $tempDurationOn = intval($tempDurationOn * $ratio);
                $tempDurationOff = $tempDuration - $tempDurationOn;
            }

            // Calculate stoppage_time for all points in the final stoppage segment
            // stoppage_time = cumulative duration from start of stoppage segment to end of data
            for ($i = $stoppageStartIndex; $i < $dataCount; $i++) {
                $this->data[$i]['stoppage_time'] = $tempDuration;
            }

            $isIgnored = $tempDuration < 60;

            // Only save stoppage detail if duration >= 60 seconds (match TractorPathService behavior)
            if (!$isIgnored) {
                $stoppageDetailIndex++;
                $displayIndex = $stoppageDetailIndex;

                $effectiveStoppageStart = $this->getEffectiveStartTime(
                    $this->data[$stoppageStartIndex]['timestamp'],
                    $workingStartTime
                );
                $effectiveStoppageEnd = $this->getEffectiveEndTime(
                    $previousPoint['timestamp'],
                    $workingEndTime
                );

                $this->stoppages[] = [
                    'index' => $displayIndex,
                    'start_time' => $effectiveStoppageStart->toTimeString(),
                    'end_time' => $effectiveStoppageEnd->toTimeString(),
                    'duration_seconds' => $tempDuration,
                    'duration_formatted' => to_time_format($tempDuration),
                    'location' => [
                        'latitude' => $this->data[$stoppageStartIndex]['latitude'],
                        'longitude' => $this->data[$stoppageStartIndex]['longitude'],
                    ],
                    'status' => $this->data[$stoppageStartIndex]['status'] == 1 ? 'on' : 'off',
                    'ignored' => false,
                ];
            } else {
                $ignoredStoppageDetailIndex++;
            }

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

        // Stoppage duration only includes stoppages >= 60 seconds (ignored stoppages excluded)
        $this->results = [
            'movement_distance_km' => round($movementDistance, 3),
            'movement_distance_meters' => round($movementDistance * 1000, 2),
            'movement_duration_seconds' => $movementDuration,
            'movement_duration_formatted' => to_time_format($movementDuration),
            'stoppage_duration_seconds' => $stoppageDuration,
            'stoppage_duration_formatted' => to_time_format($stoppageDuration),
            'stoppage_duration_while_on_seconds' => $stoppageDurationWhileOn,
            'stoppage_duration_while_on_formatted' => to_time_format($stoppageDurationWhileOn),
            'stoppage_duration_while_off_seconds' => $stoppageDurationWhileOff,
            'stoppage_duration_while_off_formatted' => to_time_format($stoppageDurationWhileOff),
            'stoppage_count' => $stoppageCount,
            'device_on_time' => $deviceOnTime,
            'first_movement_time' => $firstMovementTime,
            'start_time' => $this->data[0]['timestamp']->toTimeString(),
            'latest_status' => $this->data[$dataCount - 1]['status'],
            'average_speed' => $averageSpeed,
        ];

        return $this->results;
    }

    /**
     * Clamp a timestamp to working time boundaries
     * If timestamp is before workingStartTime, return workingStartTime
     * If timestamp is after workingEndTime, return workingEndTime
     * Otherwise return the timestamp as-is
     *
     * @param Carbon $timestamp
     * @param Carbon|null $workingStartTime
     * @param Carbon|null $workingEndTime
     * @return Carbon
     */
    private function clampToWorkingTime(Carbon $timestamp, ?Carbon $workingStartTime, ?Carbon $workingEndTime): Carbon
    {
        if ($workingStartTime && $timestamp->lt($workingStartTime)) {
            return $workingStartTime->copy();
        }
        if ($workingEndTime && $timestamp->gt($workingEndTime)) {
            return $workingEndTime->copy();
        }
        return $timestamp;
    }

    /**
     * Calculate duration between two timestamps within working time boundaries
     *
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @param Carbon|null $workingStartTime
     * @param Carbon|null $workingEndTime
     * @return int Duration in seconds
     */
    private function calculateDurationWithinWorkingTime(
        Carbon $startTime,
        Carbon $endTime,
        ?Carbon $workingStartTime,
        ?Carbon $workingEndTime
    ): int {
        // Clamp both timestamps to working time boundaries
        $clampedStart = $this->clampToWorkingTime($startTime, $workingStartTime, $workingEndTime);
        $clampedEnd = $this->clampToWorkingTime($endTime, $workingStartTime, $workingEndTime);

        // If clamped start is after clamped end, duration is 0
        if ($clampedStart->gte($clampedEnd)) {
            return 0;
        }

        return $clampedEnd->timestamp - $clampedStart->timestamp;
    }

    /**
     * Get the effective start time for a stoppage or movement segment
     * If the actual start is before workingStartTime, use workingStartTime
     *
     * @param Carbon $actualStartTime
     * @param Carbon|null $workingStartTime
     * @return Carbon
     */
    private function getEffectiveStartTime(Carbon $actualStartTime, ?Carbon $workingStartTime): Carbon
    {
        if ($workingStartTime && $actualStartTime->lt($workingStartTime)) {
            return $workingStartTime->copy();
        }
        return $actualStartTime;
    }

    /**
     * Get the effective end time for a stoppage or movement segment
     * If the actual end is after workingEndTime, use workingEndTime
     *
     * @param Carbon $actualEndTime
     * @param Carbon|null $workingEndTime
     * @return Carbon
     */
    private function getEffectiveEndTime(Carbon $actualEndTime, ?Carbon $workingEndTime): Carbon
    {
        if ($workingEndTime && $actualEndTime->gt($workingEndTime)) {
            return $workingEndTime->copy();
        }
        return $actualEndTime;
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
            'start_time' => null,
            'latest_status' => null,
            'average_speed' => 0,
        ];
    }

    /**
     * Get detailed stoppage information (optimized - returns cached data)
     * Only includes stoppages >= 60 seconds (short stoppages are excluded, matching TractorPathService behavior)
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
