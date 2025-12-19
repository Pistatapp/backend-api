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
    private ?int $workingStartTimestamp = null;
    private ?int $workingEndTimestamp = null;
    private ?Carbon $workingStartTime = null;
    private ?Carbon $workingEndTime = null;

    // Precomputed constants for Haversine formula
    private const EARTH_RADIUS_KM = 6371;

    // Business logic constants
    private const MIN_STOPPAGE_DURATION_SECONDS = 60;
    private const SECONDS_PER_HOUR = 3600;
    private const CONSECUTIVE_MOVEMENTS_FOR_FIRST_MOVEMENT = 3;

    // GPS data fields to select (minimal projection for performance)
    private const GPS_DATA_FIELDS = [
        'gps_data.coordinate',
        'gps_data.speed',
        'gps_data.status',
        'gps_data.date_time',
        'gps_data.imei',
    ];

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
        // Determine working window first (if any)
        [$startDateTime, $endDateTime] = $this->calculateWorkingWindow($tractor, $date);

        // Build minimal, ordered query (stdClass objects via toBase to reduce memory overhead)
        if ($startDateTime && $endDateTime) {
            // Fetch one point just before the window to preserve segment continuity
            $prevPoint = $tractor->gpsData()
                ->select(self::GPS_DATA_FIELDS)
                ->toBase()
                ->where('gps_data.date_time', '<', $startDateTime)
                ->orderBy('gps_data.date_time', 'desc')
                ->first();

            // The use of toBase() here tells Eloquent to return generic stdClass results instead of full Eloquent model instances.
            // This reduces memory usage and speeds up processing when only projection of fields is needed.
            // After toBase(), you get a query builder that returns simple PHP objects, not models.
            $windowPoints = $tractor->gpsData()
                ->select(self::GPS_DATA_FIELDS)
                ->toBase() // switches the result to base query so results are stdClass, not model instances
                ->whereBetween('gps_data.date_time', [$startDateTime, $endDateTime])
                ->orderBy('gps_data.date_time')
                ->get();

            $gpsData = $prevPoint ? collect([$prevPoint])->merge($windowPoints) : $windowPoints;
        } else {
            // No working window → fallback to whole day but still select minimal columns
            $gpsData = $tractor->gpsData()
                ->select(self::GPS_DATA_FIELDS)
                ->toBase()
                ->whereDate('gps_data.date_time', $date)
                ->orderBy('gps_data.date_time')
                ->get();
        }

        // Load records (data is already sorted by DB, skip re-sorting)
        $this->parseRecords($gpsData);

        return $this;
    }

    /**
     * Calculate working time window from tractor settings
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function calculateWorkingWindow(Tractor $tractor, Carbon $date): array
    {
        $startDateTime = null;
        $endDateTime = null;

        if ($tractor->start_work_time && $tractor->end_work_time) {
            $startDateTime = $date->copy()->setTimeFromTimeString($tractor->start_work_time);
            $endDateTime = $date->copy()->setTimeFromTimeString($tractor->end_work_time);
            if ($endDateTime->lt($startDateTime)) {
                $endDateTime->addDay();
            }
        }

        return [$startDateTime, $endDateTime];
    }

    /**
     * Parse coordinate from various formats (string, array, object)
     *
     * @param mixed $coord
     * @return array{0: float, 1: float}
     */
    private function parseCoordinate($coord): array
    {
        $lat = 0.0;
        $lon = 0.0;

        if (is_string($coord)) {
            $decoded = json_decode($coord, true);
            if ($decoded !== null && is_array($decoded)) {
                $lat = (float)($decoded[0] ?? 0.0);
                $lon = (float)($decoded[1] ?? 0.0);
            } else {
                // Fallback: comma-separated string
                $parts = explode(',', $coord);
                $lat = (float)($parts[0] ?? 0.0);
                $lon = (float)($parts[1] ?? 0.0);
            }
        } elseif (is_array($coord)) {
            $lat = (float)($coord[0] ?? 0.0);
            $lon = (float)($coord[1] ?? 0.0);
        }

        return [$lat, $lon];
    }

    /**
     * Parse records into internal format (optimized)
     * Stores both Carbon timestamp and Unix timestamp for fast comparisons
     *
     * @param \Illuminate\Support\Collection|array $data
     */
    private function parseRecords($data): void
    {
        foreach ($data as $record) {
            if (is_object($record)) {
                // stdClass or model - fast path for database results
                $dateTime = $record->date_time;
                $timestamp = $dateTime instanceof Carbon ? $dateTime : Carbon::parse($dateTime);
                $ts = $timestamp->timestamp;

                [$lat, $lon] = $this->parseCoordinate($record->coordinate ?? null);

                // Pre-compute radians for distance calculations
                $this->data[] = [
                    'lat' => $lat,
                    'lon' => $lon,
                    'lat_rad' => deg2rad($lat),
                    'lon_rad' => deg2rad($lon),
                    'timestamp' => $timestamp,
                    'ts' => $ts,
                    'speed' => (int)$record->speed,
                    'status' => (int)$record->status,
                    'imei' => $record->imei ?? null,
                ];
            } else {
                // Array input
                if (isset($record['coordinate'])) {
                    [$lat, $lon] = $this->parseCoordinate($record['coordinate']);
                } else {
                    $lat = (float)($record['latitude'] ?? $record['lat'] ?? 0.0);
                    $lon = (float)($record['longitude'] ?? $record['lon'] ?? 0.0);
                }

                $ts = $record['timestamp'] ?? $record['date_time'] ?? null;
                $timestamp = $ts instanceof Carbon ? $ts : ($ts ? Carbon::parse($ts) : Carbon::now());

                $this->data[] = [
                    'lat' => $lat,
                    'lon' => $lon,
                    'lat_rad' => deg2rad($lat),
                    'lon_rad' => deg2rad($lon),
                    'timestamp' => $timestamp,
                    'ts' => $timestamp->timestamp,
                    'speed' => (int)($record['speed'] ?? 0),
                    'status' => (int)($record['status'] ?? 0),
                    'imei' => $record['imei'] ?? null,
                ];
            }
        }
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
    public function analyze(?Carbon $workingStartTime = null, ?Carbon $workingEndTime = null, bool $includeDetails = true): self
    {
        if (empty($this->data)) {
            $this->getEmptyResults();
        }

        // Use stored working time if parameters are not provided
        // Use timestamps for fast comparisons
        $wsTs = $this->workingStartTimestamp;
        $weTs = $this->workingEndTimestamp;
        if ($workingStartTime !== null) {
            $wsTs = $workingStartTime->timestamp;
        }
        if ($workingEndTime !== null) {
            $weTs = $workingEndTime->timestamp;
        }
        // Keep Carbon objects for detail formatting only
        $wsCarbon = $workingStartTime ?? $this->workingStartTime;
        $weCarbon = $workingEndTime ?? $this->workingEndTime;

        // Initialize counters
        $movementDistance = 0.0;
        $movementDuration = 0;
        $stoppageDuration = 0;
        $stoppageDurationWhileOn = 0;
        $stoppageDurationWhileOff = 0;
        $stoppageCount = 0;
        $maxSpeed = 0;

        // State tracking
        $isCurrentlyStopped = false;
        $isCurrentlyMoving = false;
        $stoppageStartIndex = null;
        $movementStartIndex = null;
        $movementSegmentDistance = 0.0;

        // Detail indices (for building arrays in single pass)
        $stoppageDetailIndex = 0;
        $movementDetailIndex = 0;

        // Reset detail arrays
        if ($includeDetails) {
            $this->movements = [];
            $this->stoppages = [];
        }

        // Activation tracking
        $deviceOnTime = null;
        $firstMovementTime = null;

        // Track consecutive movements for firstMovementTime detection
        $consecutiveMovementCount = 0;
        $firstConsecutiveMovementIndex = null;

        $dataCount = count($this->data);
        $data = &$this->data; // Reference for faster access

        // Initialize stoppage_time for all points (only when details are requested)
        if ($includeDetails) {
            for ($idx = 0; $idx < $dataCount; $idx++) {
                $data[$idx]['stoppage_time'] = 0;
            }
        }

        // Previous point tracking
        $prevLat = null;
        $prevLon = null;
        $prevLatRad = null;
        $prevLonRad = null;
        $prevTs = null;
        $prevSpeed = null;
        $prevStatus = null;

        for ($index = 0; $index < $dataCount; $index++) {
            $point = &$data[$index];
            $speed = $point['speed'];
            $status = $point['status'];
            $ts = $point['ts'];

            // Track max speed
            if ($speed > $maxSpeed) {
                $maxSpeed = $speed;
            }

            // Track activation times inline (optimization: single pass)
            if ($deviceOnTime === null && $status === 1) {
                $deviceOnTime = $point['timestamp']->toTimeString();
            }

            // Inline movement/stoppage checks
            $isMoving = ($status === 1 && $speed > 0);
            $isStopped = ($speed === 0);

            // Track consecutive movements for firstMovementTime
            if ($firstMovementTime === null) {
                if ($isMoving) {
                    if ($consecutiveMovementCount === 0) {
                        $firstConsecutiveMovementIndex = $index;
                    }
                    $consecutiveMovementCount++;
                    if ($consecutiveMovementCount === self::CONSECUTIVE_MOVEMENTS_FOR_FIRST_MOVEMENT) {
                        $firstMovementTime = $data[$firstConsecutiveMovementIndex]['timestamp']->toTimeString();
                    }
                } else {
                    $consecutiveMovementCount = 0;
                    $firstConsecutiveMovementIndex = null;
                }
            }

            if ($prevTs === null) {
                // First point
                if ($isStopped) {
                    $isCurrentlyStopped = true;
                    $stoppageStartIndex = $index;
                } elseif ($isMoving) {
                    $isCurrentlyMoving = true;
                    $movementStartIndex = $index;
                    $movementSegmentDistance = 0.0;
                }
                $prevLat = $point['lat'];
                $prevLon = $point['lon'];
                $prevLatRad = $point['lat_rad'];
                $prevLonRad = $point['lon_rad'];
                $prevTs = $ts;
                $prevSpeed = $speed;
                $prevStatus = $status;
                continue;
            }

            // Calculate time difference within working time boundaries (inline for speed)
            $timeDiff = $this->calcDurationFast($prevTs, $ts, $wsTs, $weTs);

            // Transition: Moving -> Stopped
            if ($isStopped && $isCurrentlyMoving) {
                // Calculate distance using precomputed radians
                $distance = $this->haversineDistanceRad(
                    $prevLatRad,
                    $prevLonRad,
                    $point['lat_rad'],
                    $point['lon_rad']
                );
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;

                if ($includeDetails) {
                    $movementDetailIndex++;
                    $this->movements[] = $this->buildMovementDetail(
                        $data,
                        $movementStartIndex,
                        $index,
                        $movementSegmentDistance,
                        $movementDetailIndex,
                        $wsTs,
                        $weTs,
                        $wsCarbon,
                        $weCarbon
                    );
                    $data[$index - 1]['stoppage_time'] = 0;
                }

                $isCurrentlyMoving = false;
                $isCurrentlyStopped = true;
                $stoppageStartIndex = $index;
                $movementSegmentDistance = 0.0;
            }
            // Transition: Stopped -> Moving
            elseif ($isMoving && $isCurrentlyStopped) {
                $stoppageStartTs = $data[$stoppageStartIndex]['ts'];
                $tempDuration = $this->calcDurationFast($stoppageStartTs, $ts, $wsTs, $weTs);

                // Calculate on/off durations
                [$tempDurationOn, $tempDurationOff] = $this->calculateStoppageOnOffDuration(
                    $data,
                    $stoppageStartIndex,
                    $index,
                    $wsTs,
                    $weTs
                );

                if ($includeDetails) {
                    for ($i = $stoppageStartIndex; $i < $index; $i++) {
                        $data[$i]['stoppage_time'] = $tempDuration;
                    }
                    $data[$index]['stoppage_time'] = 0;
                }

                $isIgnored = $tempDuration < self::MIN_STOPPAGE_DURATION_SECONDS;

                if ($includeDetails && !$isIgnored) {
                    $stoppageDetailIndex++;
                    $this->stoppages[] = $this->buildStoppageDetail(
                        $data,
                        $stoppageStartIndex,
                        $index,
                        $tempDuration,
                        $stoppageDetailIndex,
                        $wsTs,
                        $weTs,
                        $wsCarbon,
                        $weCarbon
                    );
                }

                if ($tempDuration >= self::MIN_STOPPAGE_DURATION_SECONDS) {
                    $stoppageCount++;
                    $stoppageDuration += $tempDuration;
                    $stoppageDurationWhileOn += $tempDurationOn;
                    $stoppageDurationWhileOff += $tempDurationOff;
                } else {
                    $movementDuration += $tempDuration;
                }

                $isCurrentlyStopped = false;
                $isCurrentlyMoving = true;
                $movementStartIndex = $index;
                $movementSegmentDistance = 0.0;
            }
            // Continue moving
            elseif ($isMoving && $isCurrentlyMoving) {
                if ($includeDetails) {
                    $data[$index]['stoppage_time'] = 0;
                }

                $distance = $this->haversineDistanceRad(
                    $prevLatRad,
                    $prevLonRad,
                    $point['lat_rad'],
                    $point['lon_rad']
                );
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;
            } elseif ($isStopped && !$isCurrentlyStopped && !$isCurrentlyMoving) {
                $isCurrentlyStopped = true;
                $stoppageStartIndex = $index;
            }

            $prevLat = $point['lat'];
            $prevLon = $point['lon'];
            $prevLatRad = $point['lat_rad'];
            $prevLonRad = $point['lon_rad'];
            $prevTs = $ts;
            $prevSpeed = $speed;
            $prevStatus = $status;
        }

        // Handle final state
        if ($isCurrentlyMoving && $movementStartIndex !== null) {
            $data[$dataCount - 1]['stoppage_time'] = 0;

            if ($includeDetails) {
                $movementDetailIndex++;
                $this->movements[] = $this->buildMovementDetail(
                    $data,
                    $movementStartIndex,
                    $dataCount - 1,
                    $movementSegmentDistance,
                    $movementDetailIndex,
                    $wsTs,
                    $weTs,
                    $wsCarbon,
                    $weCarbon
                );
            }
        } elseif ($isCurrentlyStopped && $stoppageStartIndex !== null) {
            $stoppageStartTs = $data[$stoppageStartIndex]['ts'];
            $lastTs = $data[$dataCount - 1]['ts'];
            $tempDuration = $this->calcDurationFast($stoppageStartTs, $lastTs, $wsTs, $weTs);

            // Calculate on/off durations
            [$tempDurationOn, $tempDurationOff] = $this->calculateStoppageOnOffDuration(
                $data,
                $stoppageStartIndex,
                $dataCount - 1,
                $wsTs,
                $weTs
            );

            if ($includeDetails) {
                for ($i = $stoppageStartIndex; $i < $dataCount; $i++) {
                    $data[$i]['stoppage_time'] = $tempDuration;
                }
            }

            $isIgnored = $tempDuration < self::MIN_STOPPAGE_DURATION_SECONDS;

            if ($includeDetails && !$isIgnored) {
                $stoppageDetailIndex++;
                $this->stoppages[] = $this->buildStoppageDetail(
                    $data,
                    $stoppageStartIndex,
                    $dataCount - 1,
                    $tempDuration,
                    $stoppageDetailIndex,
                    $wsTs,
                    $weTs,
                    $wsCarbon,
                    $weCarbon
                );
            }

            if ($tempDuration >= self::MIN_STOPPAGE_DURATION_SECONDS) {
                $stoppageCount++;
                $stoppageDuration += $tempDuration;
                $stoppageDurationWhileOn += $tempDurationOn;
                $stoppageDurationWhileOff += $tempDurationOff;
            } else {
                $movementDuration += $tempDuration;
            }
        }

        $averageSpeed = $movementDuration > 0 ? (int)($movementDistance * self::SECONDS_PER_HOUR / $movementDuration) : 0;

        // Latest status is taken from the last processed point in the new batch
        $lastPoint = $data[$dataCount - 1];

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
            'latest_status' => $lastPoint['status'],
            'average_speed' => $averageSpeed,
        ];

        return $this;
    }

    /**
     * Fast duration calculation using timestamps (no Carbon overhead)
     */
    private function calcDurationFast(int $startTs, int $endTs, ?int $wsTs, ?int $weTs): int
    {
        // Clamp to working time boundaries
        if ($wsTs !== null && $startTs < $wsTs) {
            $startTs = $wsTs;
        }
        if ($weTs !== null && $startTs > $weTs) {
            $startTs = $weTs;
        }
        if ($wsTs !== null && $endTs < $wsTs) {
            $endTs = $wsTs;
        }
        if ($weTs !== null && $endTs > $weTs) {
            $endTs = $weTs;
        }

        return $startTs >= $endTs ? 0 : $endTs - $startTs;
    }

    /**
     * Fast Haversine distance calculation using pre-computed radians
     * Returns distance in kilometers
     */
    private function haversineDistanceRad(float $lat1Rad, float $lon1Rad, float $lat2Rad, float $lon2Rad): float
    {
        $dLat = $lat2Rad - $lat1Rad;
        $dLon = $lon2Rad - $lon1Rad;

        $a = sin($dLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * Get effective start time using timestamps for comparison, Carbon for output
     */
    private function getEffectiveStartTimeFast(int $actualTs, ?int $wsTs, Carbon $actualCarbon, ?Carbon $wsCarbon): Carbon
    {
        if ($wsTs !== null && $actualTs < $wsTs && $wsCarbon !== null) {
            return $wsCarbon;
        }
        return $actualCarbon;
    }

    /**
     * Get effective end time using timestamps for comparison, Carbon for output
     */
    private function getEffectiveEndTimeFast(int $actualTs, ?int $weTs, Carbon $actualCarbon, ?Carbon $weCarbon): Carbon
    {
        if ($weTs !== null && $actualTs > $weTs && $weCarbon !== null) {
            return $weCarbon;
        }
        return $actualCarbon;
    }

    /**
     * Calculate stoppage duration split by on/off status
     *
     * @param array $data GPS data array
     * @param int $stoppageStartIndex Index where stoppage started
     * @param int $endIndex Index where stoppage ends
     * @param ?int $wsTs Working start timestamp
     * @param ?int $weTs Working end timestamp
     * @return array{0: int, 1: int} [durationOn, durationOff]
     */
    private function calculateStoppageOnOffDuration(
        array $data,
        int $stoppageStartIndex,
        int $endIndex,
        ?int $wsTs,
        ?int $weTs
    ): array {
        $durationOn = 0;
        $durationOff = 0;
        $firstPointIndex = $stoppageStartIndex;

        // Handle case where stoppage starts before working window
        if ($wsTs !== null && $data[$stoppageStartIndex]['ts'] < $wsTs) {
            for ($i = $stoppageStartIndex + 1; $i <= $endIndex; $i++) {
                if ($data[$i]['ts'] >= $wsTs) {
                    $firstPointIndex = $i;
                    $td = $this->calcDurationFast($wsTs, $data[$i]['ts'], $wsTs, $weTs);
                    if ($data[$stoppageStartIndex]['status'] === 1) {
                        $durationOn += $td;
                    } else {
                        $durationOff += $td;
                    }
                    break;
                }
            }
            if ($firstPointIndex === $stoppageStartIndex) {
                $td = $this->calcDurationFast($wsTs, $data[$endIndex]['ts'], $wsTs, $weTs);
                if ($data[$stoppageStartIndex]['status'] === 1) {
                    $durationOn += $td;
                } else {
                    $durationOff += $td;
                }
            }
        }

        // Calculate duration for each segment
        for ($i = $firstPointIndex + 1; $i <= $endIndex; $i++) {
            $td = $this->calcDurationFast($data[$i - 1]['ts'], $data[$i]['ts'], $wsTs, $weTs);
            if ($data[$i]['status'] === 1) {
                $durationOn += $td;
            } else {
                $durationOff += $td;
            }
        }

        // Normalize if there's a discrepancy
        $totalDuration = $this->calcDurationFast(
            $data[$stoppageStartIndex]['ts'],
            $data[$endIndex]['ts'],
            $wsTs,
            $weTs
        );
        $totalCalculatedDuration = $durationOn + $durationOff;
        if ($totalCalculatedDuration > 0 && $totalCalculatedDuration !== $totalDuration) {
            $ratio = $totalDuration / $totalCalculatedDuration;
            $durationOn = (int)($durationOn * $ratio);
            $durationOff = $totalDuration - $durationOn;
        }

        return [$durationOn, $durationOff];
    }

    /**
     * Build movement detail entry
     *
     * @param array $data GPS data array
     * @param int $startIndex Movement start index
     * @param int $endIndex Movement end index
     * @param float $segmentDistance Distance in km
     * @param int $index Movement index number
     * @param ?int $wsTs Working start timestamp
     * @param ?int $weTs Working end timestamp
     * @param ?Carbon $wsCarbon Working start Carbon
     * @param ?Carbon $weCarbon Working end Carbon
     * @return array
     */
    private function buildMovementDetail(
        array $data,
        int $startIndex,
        int $endIndex,
        float $segmentDistance,
        int $index,
        ?int $wsTs,
        ?int $weTs,
        ?Carbon $wsCarbon,
        ?Carbon $weCarbon
    ): array {
        $startPoint = &$data[$startIndex];
        $endPoint = &$data[$endIndex];
        $duration = $this->calcDurationFast($startPoint['ts'], $endPoint['ts'], $wsTs, $weTs);
        $effectiveStart = $this->getEffectiveStartTimeFast(
            $startPoint['ts'],
            $wsTs,
            $startPoint['timestamp'],
            $wsCarbon
        );
        $effectiveEnd = $this->getEffectiveEndTimeFast(
            $endPoint['ts'],
            $weTs,
            $endPoint['timestamp'],
            $weCarbon
        );

        return [
            'index' => $index,
            'start_time' => $effectiveStart->toTimeString(),
            'end_time' => $effectiveEnd->toTimeString(),
            'duration_seconds' => $duration,
            'duration_formatted' => to_time_format($duration),
            'distance_km' => round($segmentDistance, 3),
            'distance_meters' => round($segmentDistance * 1000, 2),
            'start_location' => [
                'latitude' => $startPoint['lat'],
                'longitude' => $startPoint['lon'],
            ],
            'end_location' => [
                'latitude' => $endPoint['lat'],
                'longitude' => $endPoint['lon'],
            ],
            'avg_speed' => $duration > 0
                ? round(($segmentDistance / $duration) * self::SECONDS_PER_HOUR, 2)
                : 0,
        ];
    }

    /**
     * Build stoppage detail entry
     *
     * @param array $data GPS data array
     * @param int $startIndex Stoppage start index
     * @param int $endIndex Stoppage end index
     * @param int $duration Stoppage duration in seconds
     * @param int $index Stoppage index number
     * @param ?int $wsTs Working start timestamp
     * @param ?int $weTs Working end timestamp
     * @param ?Carbon $wsCarbon Working start Carbon
     * @param ?Carbon $weCarbon Working end Carbon
     * @return array
     */
    private function buildStoppageDetail(
        array $data,
        int $startIndex,
        int $endIndex,
        int $duration,
        int $index,
        ?int $wsTs,
        ?int $weTs,
        ?Carbon $wsCarbon,
        ?Carbon $weCarbon
    ): array {
        $startPoint = &$data[$startIndex];
        $endPoint = &$data[$endIndex];
        $effectiveStart = $this->getEffectiveStartTimeFast(
            $startPoint['ts'],
            $wsTs,
            $startPoint['timestamp'],
            $wsCarbon
        );
        $effectiveEnd = $this->getEffectiveEndTimeFast(
            $endPoint['ts'],
            $weTs,
            $endPoint['timestamp'],
            $weCarbon
        );

        return [
            'index' => $index,
            'start_time' => $effectiveStart->toTimeString(),
            'end_time' => $effectiveEnd->toTimeString(),
            'duration_seconds' => $duration,
            'duration_formatted' => to_time_format($duration),
            'location' => [
                'latitude' => $startPoint['lat'],
                'longitude' => $startPoint['lon'],
            ],
            'status' => $startPoint['status'] === 1 ? 'on' : 'off',
            'ignored' => false,
        ];
    }

    /**
     * Lightweight analyze method that avoids building large detail arrays for memory efficiency.
     */
    public function analyzeLight(?Carbon $workingStartTime = null, ?Carbon $workingEndTime = null): self
    {
        $this->analyze($workingStartTime, $workingEndTime, false);

        return $this;
    }

    /**
     * Get empty results structure
     */
    private function getEmptyResults(): self
    {
        $this->results = [
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
            'device_on_time' => '00:00:00',
            'first_movement_time' => '00:00:00',
            'latest_status' => 0,
            'average_speed' => 0,
        ];

        return $this;
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
     *
     * @return array<string, mixed>
     */
    public function getResults(): array
    {
        return $this->results;
    }
}
