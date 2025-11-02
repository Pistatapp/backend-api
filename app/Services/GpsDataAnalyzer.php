<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Tractor;

class GpsDataAnalyzer
{
    use GpsDataAnalyzerStreamHelpers;
    // When available, use a streaming source (cursor/generator) to minimize memory usage
    private \Traversable|array $dataSource = [];
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
        // Fetch only required columns, ordered, and as a cursor (stream)
        $cursor = $tractor->gpsData()
            ->select(['gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time'])
            ->whereDate('gps_data.date_time', $date)
            ->orderBy('gps_data.date_time')
            ->cursor();

        $this->dataSource = $this->normalizeStream($cursor);

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
        // Reset state
        $this->movements = [];
        $this->stoppages = [];
        $this->results = [];
        // Reset working time when loading records directly (will be set by loadRecordsFor if needed)
        $this->workingStartTime = null;
        $this->workingEndTime = null;
        // If a Collection was passed that's already ordered in the DB, stream it as-is
        // Otherwise, normalize and (if necessary) sort minimally in memory
        if ($data instanceof \Illuminate\Support\Collection) {
            $this->dataSource = $this->normalizeStream($data->getIterator());
        } else if (is_array($data)) {
            // Assume items already ordered by date_time; if not, sort by scalar timestamp to avoid Carbon overhead
            $normalized = [];
            foreach ($data as $record) {
                $normalized[] = $this->normalizeRecord($record);
            }
            usort($normalized, function ($a, $b) {
                return $a['ts'] <=> $b['ts'];
            });
            $this->dataSource = (function () use ($normalized) {
                foreach ($normalized as $item) {
                    yield $item;
                }
            })();
        } else if ($data instanceof \Traversable) {
            $this->dataSource = $this->normalizeStream($data);
        } else {
            // Fallback to empty
            $this->dataSource = [];
        }

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
        $iterator = $this->getIterator($this->dataSource);
        if ($iterator === null) {
            return $this->getEmptyResults();
        }

        // Use stored working time if parameters are not provided
        if ($workingStartTime === null && $this->workingStartTime !== null) {
            $workingStartTime = $this->workingStartTime;
        }
        if ($workingEndTime === null && $this->workingEndTime !== null) {
            $workingEndTime = $this->workingEndTime;
        }

        // Convert working bounds to scalar timestamps for faster math
        $ws = $workingStartTime ? $workingStartTime->timestamp : null;
        $we = $workingEndTime ? $workingEndTime->timestamp : null;

        // Initialize counters
        $movementDistance = 0;
        $movementDuration = 0;
        $stoppageDuration = 0;
        $stoppageDurationWhileOn = 0;
        $stoppageDurationWhileOff = 0;
        $stoppageCount = 0;
        $ignoredStoppageCount = 0;
        $ignoredStoppageDuration = 0;

        // State tracking
        $previousPoint = null; // ['lat','lng','ts','speed','status']
        $isCurrentlyStopped = false;
        $isCurrentlyMoving = false;
        $stoppageStart = null; // point array at start of stoppage
        $movementStart = null; // point array at start of movement
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
        $firstConsecutiveMovementPoint = null;

        // Helper function to check if point is moving
        $isMovingPoint = function($point) {
            return $point['status'] == 1 && $point['speed'] > 0;
        };

        // Helper function to check if point is stopped
        $isStoppedPoint = function($point) {
            return $point['speed'] == 0;
        };

        $firstPoint = null;
        $lastStatus = null;
        $stoppageSegOn = 0;   // seconds within current stoppage segment while device is ON
        $stoppageSegOff = 0;  // seconds within current stoppage segment while device is OFF

        foreach ($iterator as $currentPoint) {
            // Track activation times inline (optimization: single pass)
            if ($deviceOnTime === null && $currentPoint['status'] == 1) {
                $deviceOnTime = Carbon::createFromTimestamp($currentPoint['ts'])->toTimeString();
            }

            // Track consecutive movements for firstMovementTime
            if ($firstMovementTime === null) {
                if ($isMovingPoint($currentPoint)) {
                    // Start or continue consecutive movement sequence
                    if ($consecutiveMovementCount == 0) {
                        $firstConsecutiveMovementPoint = $currentPoint;
                    }
                    $consecutiveMovementCount++;

                    // When we reach 3 consecutive movements, set firstMovementTime to the first point
                    if ($consecutiveMovementCount == 3) {
                        $firstMovementTime = Carbon::createFromTimestamp($firstConsecutiveMovementPoint['ts'])->toTimeString();
                    }
                } else {
                    // Non-moving point breaks the sequence
                    $consecutiveMovementCount = 0;
                    $firstConsecutiveMovementPoint = null;
                }
            }

            if ($firstPoint === null) {
                $firstPoint = $currentPoint;
            }
            $lastStatus = $currentPoint['status'];

            if ($previousPoint === null) {
                // First point
                if ($isStoppedPoint($currentPoint)) {
                    // First point is stoppage
                    $isCurrentlyStopped = true;
                    $stoppageStart = $currentPoint;
                } else if ($isMovingPoint($currentPoint)) {
                    // First point is movement
                    $isCurrentlyMoving = true;
                    $movementStart = $currentPoint;
                    $movementSegmentDistance = 0;
                }
                $previousPoint = $currentPoint;
                continue;
            }

            // Calculate time difference within working time boundaries
            $timeDiff = $this->calculateDurationWithinRange(
                $previousPoint['ts'],
                $currentPoint['ts'],
                $ws,
                $we
            );
            $isStopped = $isStoppedPoint($currentPoint);
            $isMoving = $isMovingPoint($currentPoint);

            // While inside a stoppage segment, accumulate on/off duration per interval
            if ($isCurrentlyStopped && $isStopped && $previousPoint !== null && $timeDiff > 0) {
                if ($currentPoint['status'] == 1) {
                    $stoppageSegOn += $timeDiff;
                } else {
                    $stoppageSegOff += $timeDiff;
                }
            }

            // Transition: Moving -> Stopped
            if ($isStopped && $isCurrentlyMoving) {
                // Add transition to movement
                $distance = calculate_distance(
                    [$previousPoint['lat'], $previousPoint['lng']],
                    [$currentPoint['lat'], $currentPoint['lng']]
                );
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;

                // Save movement detail
                $movementDetailIndex++;
                $effectiveMovementStartTs = $this->getEffectiveStartTs($movementStart['ts'], $ws);
                $effectiveMovementEndTs = $this->getEffectiveEndTs($currentPoint['ts'], $we);
                $duration = $this->calculateDurationWithinRange(
                    $movementStart['ts'],
                    $currentPoint['ts'],
                    $ws,
                    $we
                );
                $this->movements[] = [
                    'index' => $movementDetailIndex,
                    'start_time' => Carbon::createFromTimestamp($effectiveMovementStartTs)->toTimeString(),
                    'end_time' => Carbon::createFromTimestamp($effectiveMovementEndTs)->toTimeString(),
                    'duration_seconds' => $duration,
                    'duration_formatted' => to_time_format($duration),
                    'distance_km' => round($movementSegmentDistance, 3),
                    'distance_meters' => round($movementSegmentDistance * 1000, 2),
                    'start_location' => [
                        'latitude' => $movementStart['lat'],
                        'longitude' => $movementStart['lng'],
                    ],
                    'end_location' => [
                        'latitude' => $currentPoint['lat'],
                        'longitude' => $currentPoint['lng'],
                    ],
                    'avg_speed' => $duration > 0 ? round(($movementSegmentDistance / $duration) * 3600, 2) : 0,
                ];

                // End of movement / Start of stoppage
                $isCurrentlyMoving = false;
                $isCurrentlyStopped = true;
                $stoppageStart = $currentPoint;
                $stoppageSegOn = 0;
                $stoppageSegOff = 0;
                $movementSegmentDistance = 0;
            }
            // Transition: Stopped -> Moving
            elseif ($isMoving && $isCurrentlyStopped) {
                // Calculate stoppage inline with working time boundaries
                // Get effective start and end times for the stoppage segment
                $stoppageStartTimestamp = $stoppageStart['ts'];
                $stoppageEndTimestamp = $currentPoint['ts'];

                // Calculate total duration within working time boundaries
                $tempDuration = $this->calculateDurationWithinRange(
                    $stoppageStartTimestamp,
                    $stoppageEndTimestamp,
                    $ws,
                    $we
                );

                // On/off durations have been incrementally accumulated during the stoppage segment
                $tempDurationOn = $stoppageSegOn;
                $tempDurationOff = $stoppageSegOff;

                // Calculate stoppage_time for all points in the stoppage segment
                // We do not annotate per-point stoppage_time to avoid high memory usage

                // End of stoppage / Start of movement
                $isIgnored = $tempDuration < 60;

                // Only save stoppage detail if duration >= 60 seconds (match TractorPathService behavior)
                if (!$isIgnored) {
                    $stoppageDetailIndex++;
                    $displayIndex = $stoppageDetailIndex;
                    $effectiveStoppageStartTs = $this->getEffectiveStartTs($stoppageStart['ts'], $ws);
                    $effectiveStoppageEndTs = $this->getEffectiveEndTs($currentPoint['ts'], $we);

                    $this->stoppages[] = [
                        'index' => $displayIndex,
                        'start_time' => Carbon::createFromTimestamp($effectiveStoppageStartTs)->toTimeString(),
                        'end_time' => Carbon::createFromTimestamp($effectiveStoppageEndTs)->toTimeString(),
                        'duration_seconds' => $tempDuration,
                        'duration_formatted' => to_time_format($tempDuration),
                        'location' => [
                            'latitude' => $stoppageStart['lat'],
                            'longitude' => $stoppageStart['lng'],
                        ],
                        'status' => $stoppageStart['status'] == 1 ? 'on' : 'off',
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
                $movementStart = $currentPoint;
                $movementSegmentDistance = 0;
            }
            // Continue moving
            elseif ($isMoving && $isCurrentlyMoving) {
                // Only count movement if both points are within working time or the segment crosses working time
                // Distance is always counted, but duration only within working time
                $distance = calculate_distance(
                    [$previousPoint['lat'], $previousPoint['lng']],
                    [$currentPoint['lat'], $currentPoint['lng']]
                );
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;
            }
            // First stoppage after movement (shouldn't happen with above logic, but safe)
            elseif ($isStopped && !$isCurrentlyStopped && !$isCurrentlyMoving) {
                $isCurrentlyStopped = true;
                $stoppageStart = $currentPoint;
            }

            $previousPoint = $currentPoint;
        }

        // Handle final state
        if ($isCurrentlyMoving && $movementStart !== null) {
            // End final movement
            $movementDetailIndex++;
            $effectiveMovementStartTs = $this->getEffectiveStartTs($movementStart['ts'], $ws);
            $effectiveMovementEndTs = $this->getEffectiveEndTs($previousPoint['ts'], $we);
            $duration = $this->calculateDurationWithinRange(
                $movementStart['ts'],
                $previousPoint['ts'],
                $ws,
                $we
            );
            $this->movements[] = [
                'index' => $movementDetailIndex,
                'start_time' => Carbon::createFromTimestamp($effectiveMovementStartTs)->toTimeString(),
                'end_time' => Carbon::createFromTimestamp($effectiveMovementEndTs)->toTimeString(),
                'duration_seconds' => $duration,
                'duration_formatted' => to_time_format($duration),
                'distance_km' => round($movementSegmentDistance, 3),
                'distance_meters' => round($movementSegmentDistance * 1000, 2),
                'start_location' => [
                    'latitude' => $movementStart['lat'],
                    'longitude' => $movementStart['lng'],
                ],
                'end_location' => [
                    'latitude' => $previousPoint['lat'],
                    'longitude' => $previousPoint['lng'],
                ],
                'avg_speed' => $duration > 0 ? round(($movementSegmentDistance / $duration) * 3600, 2) : 0,
            ];
        } elseif ($isCurrentlyStopped && $stoppageStart !== null) {
            // End final stoppage
            $stoppageStartTimestamp = $stoppageStart['ts'];
            $stoppageEndTimestamp = $previousPoint['ts'];

            // Calculate total duration within working time boundaries
            $tempDuration = $this->calculateDurationWithinRange(
                $stoppageStartTimestamp,
                $stoppageEndTimestamp,
                $ws,
                $we
            );

            // On/off durations accumulated during the stoppage segment
            $tempDurationOn = $stoppageSegOn;
            $tempDurationOff = $stoppageSegOff;

            // Calculate stoppage_time for all points in the final stoppage segment
            // Skipped to preserve memory

            $isIgnored = $tempDuration < 60;

            // Only save stoppage detail if duration >= 60 seconds (match TractorPathService behavior)
            if (!$isIgnored) {
                $stoppageDetailIndex++;
                $displayIndex = $stoppageDetailIndex;
                $effectiveStoppageStartTs = $this->getEffectiveStartTs($stoppageStart['ts'], $ws);
                $effectiveStoppageEndTs = $this->getEffectiveEndTs($previousPoint['ts'], $we);

                $this->stoppages[] = [
                    'index' => $displayIndex,
                    'start_time' => Carbon::createFromTimestamp($effectiveStoppageStartTs)->toTimeString(),
                    'end_time' => Carbon::createFromTimestamp($effectiveStoppageEndTs)->toTimeString(),
                    'duration_seconds' => $tempDuration,
                    'duration_formatted' => to_time_format($tempDuration),
                    'location' => [
                        'latitude' => $stoppageStart['lat'],
                        'longitude' => $stoppageStart['lng'],
                    ],
                    'status' => $stoppageStart['status'] == 1 ? 'on' : 'off',
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
            'movement_distance_km' => round($movementDistance, 1),
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
            'start_time' => $firstPoint ? Carbon::createFromTimestamp($firstPoint['ts'])->toTimeString() : null,
            'latest_status' => $lastStatus,
            'average_speed' => $averageSpeed,
        ];

        return $this->results;
    }

    // Optimized, scalar-based time helpers
    private function clampToWorkingTs(int $ts, ?int $ws, ?int $we): int
    {
        if ($ws !== null && $ts < $ws) {
            return $ws;
        }
        if ($we !== null && $ts > $we) {
            return $we;
        }
        return $ts;
    }

    private function calculateDurationWithinRange(int $startTs, int $endTs, ?int $ws, ?int $we): int
    {
        $cs = $this->clampToWorkingTs($startTs, $ws, $we);
        $ce = $this->clampToWorkingTs($endTs, $ws, $we);
        if ($cs >= $ce) {
            return 0;
        }
        return $ce - $cs;
    }

    /**
     * Get the effective start time for a stoppage or movement segment
     * If the actual start is before workingStartTime, use workingStartTime
     *
     * @param Carbon $actualStartTime
     * @param Carbon|null $workingStartTime
     * @return Carbon
     */
    private function getEffectiveStartTs(int $actualStartTs, ?int $ws): int
    {
        if ($ws !== null && $actualStartTs < $ws) {
            return $ws;
        }
        return $actualStartTs;
    }

    /**
     * Get the effective end time for a stoppage or movement segment
     * If the actual end is after workingEndTime, use workingEndTime
     *
     * @param Carbon $actualEndTime
     * @param Carbon|null $workingEndTime
     * @return Carbon
     */
    private function getEffectiveEndTs(int $actualEndTs, ?int $we): int
    {
        if ($we !== null && $actualEndTs > $we) {
            return $we;
        }
        return $actualEndTs;
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

// Internal helpers for normalization and streaming
trait GpsDataAnalyzerStreamHelpers
{
    private function normalizeRecord($record): array
    {
        if (is_object($record)) {
            $ts = $record->date_time;
            $timestamp = is_string($ts) ? \Carbon\Carbon::parse($ts)->timestamp : $ts->timestamp;
            return [
                'lat' => is_array($record->coordinate) ? $record->coordinate[0] : ($record->coordinate[0] ?? null),
                'lng' => is_array($record->coordinate) ? $record->coordinate[1] : ($record->coordinate[1] ?? null),
                'ts' => $timestamp,
                'speed' => (float)$record->speed,
                'status' => (int)$record->status,
            ];
        }
        // Array already
        // Expect keys: latitude/longitude or lat/lng and timestamp or ts
        $timestamp = isset($record['ts']) ? (int)$record['ts'] : (isset($record['timestamp']) ? ($record['timestamp'] instanceof \Carbon\Carbon ? $record['timestamp']->timestamp : (int)$record['timestamp']) : 0);
        $lat = $record['lat'] ?? $record['latitude'] ?? null;
        $lng = $record['lng'] ?? $record['longitude'] ?? null;
        return [
            'lat' => $lat,
            'lng' => $lng,
            'ts' => $timestamp,
            'speed' => (float)($record['speed'] ?? 0),
            'status' => (int)($record['status'] ?? 0),
        ];
    }

    private function normalizeStream($iterable): \Generator
    {
        foreach ($iterable as $item) {
            yield $this->normalizeRecord($item);
        }
    }

    private function getIterator($dataSource): ?\Traversable
    {
        if ($dataSource instanceof \Traversable) {
            return $dataSource;
        }
        if (is_array($dataSource)) {
            return (function () use ($dataSource) {
                foreach ($dataSource as $item) {
                    yield $item;
                }
            })();
        }
        return null;
    }
}
