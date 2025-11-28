<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Tractor;
use App\Services\Gps\GpsAnalysisState;
use App\Services\Gps\GpsAnalysisCacheService;

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

    // For incremental processing
    private ?Tractor $tractor = null;
    private ?Carbon $date = null;

    // Precomputed constants for Haversine formula
    private const EARTH_RADIUS_KM = 6371;

    public function __construct(
        private readonly GpsAnalysisCacheService $cacheService)
    {}

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
        // Store for incremental processing
        $this->tractor = $tractor;
        $this->date = $date->copy();

        // Determine working window first (if any)
        $startDateTime = null;
        $endDateTime = null;
        if ($tractor->start_work_time && $tractor->end_work_time) {
            $startDateTime = $date->copy()->setTimeFromTimeString($tractor->start_work_time);
            $endDateTime = $date->copy()->setTimeFromTimeString($tractor->end_work_time);
            if ($endDateTime->lt($startDateTime)) {
                $endDateTime->addDay();
            }
        }

        // Build minimal, ordered query (stdClass objects via toBase to reduce memory overhead)
        if ($startDateTime && $endDateTime) {
            // Fetch one point just before the window to preserve segment continuity
            $prevPoint = $tractor->gpsData()
                ->select(['gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time', 'gps_data.imei'])
                ->toBase()
                ->where('gps_data.date_time', '<', $startDateTime)
                ->orderBy('gps_data.date_time', 'desc')
                ->first();

            // The use of toBase() here tells Eloquent to return generic stdClass results instead of full Eloquent model instances.
            // This reduces memory usage and speeds up processing when only projection of fields is needed.
            // After toBase(), you get a query builder that returns simple PHP objects, not models.
            $windowPoints = $tractor->gpsData()
                ->select(['gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time', 'gps_data.imei'])
                ->toBase() // switches the result to base query so results are stdClass, not model instances
                ->whereBetween('gps_data.date_time', [$startDateTime, $endDateTime])
                ->orderBy('gps_data.date_time')
                ->get();

            $gpsData = $prevPoint ? collect([$prevPoint])->merge($windowPoints) : $windowPoints;
        } else {
            // No working window → fallback to whole day but still select minimal columns
            $gpsData = $tractor->gpsData()
                ->select(['gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time', 'gps_data.imei'])
                ->toBase()
                ->whereDate('gps_data.date_time', $date)
                ->orderBy('gps_data.date_time')
                ->get();
        }

        // Load records (data is already sorted by DB, skip re-sorting)
        $this->loadFromRecordsPreSorted($gpsData);

        // Store working window for downstream analyze() if applicable
        if ($startDateTime && $endDateTime) {
            $this->workingStartTime = $startDateTime;
            $this->workingEndTime = $endDateTime;
            $this->workingStartTimestamp = $startDateTime->timestamp;
            $this->workingEndTimestamp = $endDateTime->timestamp;
        } else {
            $this->workingStartTime = null;
            $this->workingEndTime = null;
            $this->workingStartTimestamp = null;
            $this->workingEndTimestamp = null;
        }

        return $this;
    }

    /**
     * Load only NEW GPS records since the last cached state (incremental loading)
     * This is the key optimization for real-time dashboards
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @param GpsAnalysisState|null $cachedState Previous state to continue from
     * @return self
     */
    public function loadRecordsIncremental(Tractor $tractor, Carbon $date, ?GpsAnalysisState $cachedState = null): self
    {
        $this->tractor = $tractor;
        $this->date = $date->copy();

        // Determine working window
        $startDateTime = null;
        $endDateTime = null;
        if ($tractor->start_work_time && $tractor->end_work_time) {
            $startDateTime = $date->copy()->setTimeFromTimeString($tractor->start_work_time);
            $endDateTime = $date->copy()->setTimeFromTimeString($tractor->end_work_time);
            if ($endDateTime->lt($startDateTime)) {
                $endDateTime->addDay();
            }
        }

        // Store working window
        if ($startDateTime && $endDateTime) {
            $this->workingStartTime = $startDateTime;
            $this->workingEndTime = $endDateTime;
            $this->workingStartTimestamp = $startDateTime->timestamp;
            $this->workingEndTimestamp = $endDateTime->timestamp;
        } else {
            $this->workingStartTime = null;
            $this->workingEndTime = null;
            $this->workingStartTimestamp = null;
            $this->workingEndTimestamp = null;
        }

        // Determine query start time
        $queryStartTime = $startDateTime;
        if ($cachedState !== null && $cachedState->hasData()) {
            // Start from last processed timestamp (convert to Carbon for query)
            $lastProcessedTime = Carbon::createFromTimestamp($cachedState->lastProcessedTimestamp);
            // Query from last processed time (we'll skip already processed points in analyze)
            $queryStartTime = $lastProcessedTime;
        }

        // Build query for new data only
        $query = $tractor->gpsData()
            ->select(['gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time', 'gps_data.imei'])
            ->toBase()
            ->orderBy('gps_data.date_time');

        if ($queryStartTime && $endDateTime) {
            $query->where('gps_data.date_time', '>=', $queryStartTime)
                  ->where('gps_data.date_time', '<=', $endDateTime);
        } elseif ($queryStartTime) {
            $query->where('gps_data.date_time', '>=', $queryStartTime)
                  ->whereDate('gps_data.date_time', $date);
        } else {
            $query->whereDate('gps_data.date_time', $date);
        }

        $gpsData = $query->get();

        // Load records (already sorted by DB)
        $this->loadFromRecordsPreSorted($gpsData);

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
        $this->resetState();
        $this->parseRecords($data);

        // Sort by timestamp
        usort($this->data, fn($a, $b) => $a['ts'] <=> $b['ts']);

        return $this;
    }

    /**
     * Load GPS data from pre-sorted records (skips sorting for performance)
     * Use this when data is already sorted by timestamp (e.g., from database ORDER BY)
     *
     * @param \Illuminate\Support\Collection|array $data
     * @return self
     */
    public function loadFromRecordsPreSorted($data): self
    {
        $this->resetState();
        $this->parseRecords($data);
        return $this;
    }

    /**
     * Reset internal state
     */
    private function resetState(): void
    {
        $this->data = [];
        $this->movements = [];
        $this->stoppages = [];
        $this->results = [];
        $this->workingStartTime = null;
        $this->workingEndTime = null;
        $this->workingStartTimestamp = null;
        $this->workingEndTimestamp = null;
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

                // Parse coordinate (JSON decoded array or string)
                $coord = $record->coordinate;
                if (is_string($coord)) {
                    // Handle JSON string from database
                    $decoded = json_decode($coord, true);
                    if ($decoded !== null) {
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
                } else {
                    $lat = 0.0;
                    $lon = 0.0;
                }

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
                $lat = 0.0;
                $lon = 0.0;
                if (isset($record['coordinate'])) {
                    $coord = $record['coordinate'];
                    if (is_string($coord)) {
                        $decoded = json_decode($coord, true);
                        if ($decoded !== null) {
                            $lat = (float)($decoded[0] ?? 0.0);
                            $lon = (float)($decoded[1] ?? 0.0);
                        } else {
                            $parts = explode(',', $coord);
                            $lat = (float)($parts[0] ?? 0.0);
                            $lon = (float)($parts[1] ?? 0.0);
                        }
                    } elseif (is_array($coord)) {
                        $lat = (float)($coord[0] ?? 0.0);
                        $lon = (float)($coord[1] ?? 0.0);
                    }
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
    public function analyze(?Carbon $workingStartTime = null, ?Carbon $workingEndTime = null, bool $includeDetails = true): array
    {
        if (empty($this->data)) {
            return $this->getEmptyResults();
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
                    if ($consecutiveMovementCount === 3) {
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
                    $prevLatRad, $prevLonRad,
                    $point['lat_rad'], $point['lon_rad']
                );
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;

                if ($includeDetails) {
                    $movementDetailIndex++;
                    $startPoint = &$data[$movementStartIndex];
                    $duration = $this->calcDurationFast($startPoint['ts'], $ts, $wsTs, $weTs);
                    $effectiveStart = $this->getEffectiveStartTimeFast($startPoint['ts'], $wsTs, $startPoint['timestamp'], $wsCarbon);
                    $effectiveEnd = $this->getEffectiveEndTimeFast($ts, $weTs, $point['timestamp'], $weCarbon);

                    $this->movements[] = [
                        'index' => $movementDetailIndex,
                        'start_time' => $effectiveStart->toTimeString(),
                        'end_time' => $effectiveEnd->toTimeString(),
                        'duration_seconds' => $duration,
                        'duration_formatted' => to_time_format($duration),
                        'distance_km' => round($movementSegmentDistance, 3),
                        'distance_meters' => round($movementSegmentDistance * 1000, 2),
                        'start_location' => [
                            'latitude' => $startPoint['lat'],
                            'longitude' => $startPoint['lon'],
                        ],
                        'end_location' => [
                            'latitude' => $point['lat'],
                            'longitude' => $point['lon'],
                        ],
                        'avg_speed' => $duration > 0 ? round(($movementSegmentDistance / $duration) * 3600, 2) : 0,
                    ];
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
                $tempDurationOn = 0;
                $tempDurationOff = 0;
                $firstPointIndex = $stoppageStartIndex;

                if ($wsTs !== null && $stoppageStartTs < $wsTs) {
                    for ($i = $stoppageStartIndex + 1; $i <= $index; $i++) {
                        if ($data[$i]['ts'] >= $wsTs) {
                            $firstPointIndex = $i;
                            $td = $this->calcDurationFast($wsTs, $data[$i]['ts'], $wsTs, $weTs);
                            if ($data[$stoppageStartIndex]['status'] === 1) {
                                $tempDurationOn += $td;
                            } else {
                                $tempDurationOff += $td;
                            }
                            break;
                        }
                    }
                    if ($firstPointIndex === $stoppageStartIndex) {
                        $td = $this->calcDurationFast($wsTs, $ts, $wsTs, $weTs);
                        if ($data[$stoppageStartIndex]['status'] === 1) {
                            $tempDurationOn += $td;
                        } else {
                            $tempDurationOff += $td;
                        }
                    }
                }

                for ($i = $firstPointIndex + 1; $i <= $index; $i++) {
                    $td = $this->calcDurationFast($data[$i - 1]['ts'], $data[$i]['ts'], $wsTs, $weTs);
                    if ($data[$i]['status'] === 1) {
                        $tempDurationOn += $td;
                    } else {
                        $tempDurationOff += $td;
                    }
                }

                $totalCalculatedDuration = $tempDurationOn + $tempDurationOff;
                if ($totalCalculatedDuration > 0 && $totalCalculatedDuration !== $tempDuration) {
                    $ratio = $tempDuration / $totalCalculatedDuration;
                    $tempDurationOn = (int)($tempDurationOn * $ratio);
                    $tempDurationOff = $tempDuration - $tempDurationOn;
                }

                if ($includeDetails) {
                    for ($i = $stoppageStartIndex; $i < $index; $i++) {
                        $data[$i]['stoppage_time'] = $tempDuration;
                    }
                    $data[$index]['stoppage_time'] = 0;
                }

                $isIgnored = $tempDuration < 60;

                if ($includeDetails && !$isIgnored) {
                    $stoppageDetailIndex++;
                    $startPoint = &$data[$stoppageStartIndex];
                    $effectiveStart = $this->getEffectiveStartTimeFast($startPoint['ts'], $wsTs, $startPoint['timestamp'], $wsCarbon);
                    $effectiveEnd = $this->getEffectiveEndTimeFast($ts, $weTs, $point['timestamp'], $weCarbon);

                    $this->stoppages[] = [
                        'index' => $stoppageDetailIndex,
                        'start_time' => $effectiveStart->toTimeString(),
                        'end_time' => $effectiveEnd->toTimeString(),
                        'duration_seconds' => $tempDuration,
                        'duration_formatted' => to_time_format($tempDuration),
                        'location' => [
                            'latitude' => $startPoint['lat'],
                            'longitude' => $startPoint['lon'],
                        ],
                        'status' => $startPoint['status'] === 1 ? 'on' : 'off',
                        'ignored' => false,
                    ];
                }

                if ($tempDuration >= 60) {
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
                    $prevLatRad, $prevLonRad,
                    $point['lat_rad'], $point['lon_rad']
                );
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;
            }
            elseif ($isStopped && !$isCurrentlyStopped && !$isCurrentlyMoving) {
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
                $startPoint = &$data[$movementStartIndex];
                $lastPoint = &$data[$dataCount - 1];
                $duration = $this->calcDurationFast($startPoint['ts'], $lastPoint['ts'], $wsTs, $weTs);
                $effectiveStart = $this->getEffectiveStartTimeFast($startPoint['ts'], $wsTs, $startPoint['timestamp'], $wsCarbon);
                $effectiveEnd = $this->getEffectiveEndTimeFast($lastPoint['ts'], $weTs, $lastPoint['timestamp'], $weCarbon);

                $this->movements[] = [
                    'index' => $movementDetailIndex,
                    'start_time' => $effectiveStart->toTimeString(),
                    'end_time' => $effectiveEnd->toTimeString(),
                    'duration_seconds' => $duration,
                    'duration_formatted' => to_time_format($duration),
                    'distance_km' => round($movementSegmentDistance, 3),
                    'distance_meters' => round($movementSegmentDistance * 1000, 2),
                    'start_location' => [
                        'latitude' => $startPoint['lat'],
                        'longitude' => $startPoint['lon'],
                    ],
                    'end_location' => [
                        'latitude' => $lastPoint['lat'],
                        'longitude' => $lastPoint['lon'],
                    ],
                    'avg_speed' => $duration > 0 ? round(($movementSegmentDistance / $duration) * 3600, 2) : 0,
                ];
            }
        } elseif ($isCurrentlyStopped && $stoppageStartIndex !== null) {
            $stoppageStartTs = $data[$stoppageStartIndex]['ts'];
            $lastTs = $data[$dataCount - 1]['ts'];
            $tempDuration = $this->calcDurationFast($stoppageStartTs, $lastTs, $wsTs, $weTs);

            $tempDurationOn = 0;
            $tempDurationOff = 0;
            $firstPointIndex = $stoppageStartIndex;

            if ($wsTs !== null && $stoppageStartTs < $wsTs) {
                for ($i = $stoppageStartIndex + 1; $i < $dataCount; $i++) {
                    if ($data[$i]['ts'] >= $wsTs) {
                        $firstPointIndex = $i;
                        $td = $this->calcDurationFast($wsTs, $data[$i]['ts'], $wsTs, $weTs);
                        if ($data[$stoppageStartIndex]['status'] === 1) {
                            $tempDurationOn += $td;
                        } else {
                            $tempDurationOff += $td;
                        }
                        break;
                    }
                }
                if ($firstPointIndex === $stoppageStartIndex) {
                    $td = $this->calcDurationFast($wsTs, $lastTs, $wsTs, $weTs);
                    if ($data[$stoppageStartIndex]['status'] === 1) {
                        $tempDurationOn += $td;
                    } else {
                        $tempDurationOff += $td;
                    }
                }
            }

            for ($i = $firstPointIndex + 1; $i < $dataCount; $i++) {
                $td = $this->calcDurationFast($data[$i - 1]['ts'], $data[$i]['ts'], $wsTs, $weTs);
                if ($data[$i]['status'] === 1) {
                    $tempDurationOn += $td;
                } else {
                    $tempDurationOff += $td;
                }
            }

            $totalCalculatedDuration = $tempDurationOn + $tempDurationOff;
            if ($totalCalculatedDuration > 0 && $totalCalculatedDuration !== $tempDuration) {
                $ratio = $tempDuration / $totalCalculatedDuration;
                $tempDurationOn = (int)($tempDurationOn * $ratio);
                $tempDurationOff = $tempDuration - $tempDurationOn;
            }

            if ($includeDetails) {
                for ($i = $stoppageStartIndex; $i < $dataCount; $i++) {
                    $data[$i]['stoppage_time'] = $tempDuration;
                }
            }

            $isIgnored = $tempDuration < 60;

            if ($includeDetails && !$isIgnored) {
                $stoppageDetailIndex++;
                $startPoint = &$data[$stoppageStartIndex];
                $lastPoint = &$data[$dataCount - 1];
                $effectiveStart = $this->getEffectiveStartTimeFast($startPoint['ts'], $wsTs, $startPoint['timestamp'], $wsCarbon);
                $effectiveEnd = $this->getEffectiveEndTimeFast($lastPoint['ts'], $weTs, $lastPoint['timestamp'], $weCarbon);

                $this->stoppages[] = [
                    'index' => $stoppageDetailIndex,
                    'start_time' => $effectiveStart->toTimeString(),
                    'end_time' => $effectiveEnd->toTimeString(),
                    'duration_seconds' => $tempDuration,
                    'duration_formatted' => to_time_format($tempDuration),
                    'location' => [
                        'latitude' => $startPoint['lat'],
                        'longitude' => $startPoint['lon'],
                    ],
                    'status' => $startPoint['status'] === 1 ? 'on' : 'off',
                    'ignored' => false,
                ];
            }

            if ($tempDuration >= 60) {
                $stoppageCount++;
                $stoppageDuration += $tempDuration;
                $stoppageDurationWhileOn += $tempDurationOn;
                $stoppageDurationWhileOff += $tempDurationOff;
            } else {
                $movementDuration += $tempDuration;
            }
        }

        $averageSpeed = $movementDuration > 0 ? (int)($movementDistance * 3600 / $movementDuration) : 0;

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
            'start_time' => $data[0]['timestamp']->toTimeString(),
            'latest_status' => $data[$dataCount - 1]['status'],
            'average_speed' => $averageSpeed,
        ];

        return $this->results;
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
     * Lightweight analyze method that avoids building large detail arrays for memory efficiency.
     */
    public function analyzeLight(?Carbon $workingStartTime = null, ?Carbon $workingEndTime = null): array
    {
        return $this->analyze($workingStartTime, $workingEndTime, false);
    }

    /**
     * MAIN OPTIMIZED ENTRY POINT: Load and analyze with caching
     * This method checks cache FIRST, then only loads new data from DB
     *
     * Flow:
     * 1. Check cache for existing state
     * 2. If no cache → full load from DB, full analysis, save to cache
     * 3. If cache exists → load ONLY new points since last_processed_timestamp
     * 4. If no new points → return cached results immediately (fastest path)
     * 5. If new points → incremental analysis, merge with cached state
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @param bool $includeDetails
     * @return array
     */
    public function loadAndAnalyzeWithCache(Tractor $tractor, Carbon $date, bool $includeDetails = false): array
    {
        $this->tractor = $tractor;
        $this->date = $date->copy();
        $tractorId = $tractor->id;

        // Step 1: Determine working window
        $startDateTime = null;
        $endDateTime = null;
        if ($tractor->start_work_time && $tractor->end_work_time) {
            $startDateTime = $date->copy()->setTimeFromTimeString($tractor->start_work_time);
            $endDateTime = $date->copy()->setTimeFromTimeString($tractor->end_work_time);
            if ($endDateTime->lt($startDateTime)) {
                $endDateTime->addDay();
            }
        }

        // Store working window
        if ($startDateTime && $endDateTime) {
            $this->workingStartTime = $startDateTime;
            $this->workingEndTime = $endDateTime;
            $this->workingStartTimestamp = $startDateTime->timestamp;
            $this->workingEndTimestamp = $endDateTime->timestamp;
        } else {
            $this->workingStartTime = null;
            $this->workingEndTime = null;
            $this->workingStartTimestamp = null;
            $this->workingEndTimestamp = null;
        }

        // Step 2: Check cache FIRST (before any DB query)
        $cachedState = $this->cacheService->getState($tractorId, $date);

        if ($cachedState === null) {
            // No cache - do full load and analysis
            return $this->doFullLoadAndAnalysis($tractor, $date, $startDateTime, $endDateTime, $includeDetails);
        }

        // Step 3: Cache exists - check for new data with minimal query
        $lastProcessedTime = Carbon::createFromTimestamp($cachedState->lastProcessedTimestamp);

        // Query ONLY for count of new records (very fast)
        $newRecordsQuery = $tractor->gpsData()->toBase();
        if ($startDateTime && $endDateTime) {
            $newRecordsQuery->where('gps_data.date_time', '>', $lastProcessedTime)
                           ->where('gps_data.date_time', '<=', $endDateTime);
        } else {
            $newRecordsQuery->where('gps_data.date_time', '>', $lastProcessedTime)
                           ->whereDate('gps_data.date_time', $date);
        }

        $newRecordsCount = $newRecordsQuery->count();

        if ($newRecordsCount === 0) {
            // FASTEST PATH: No new data - return cached results immediately
            $this->results = $this->buildResultsFromState($cachedState);
            return $this->results;
        }

        // Step 4: Load ONLY new records
        $newRecords = $tractor->gpsData()
            ->select(['gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time', 'gps_data.imei'])
            ->toBase()
            ->where('gps_data.date_time', '>', $lastProcessedTime);

        if ($endDateTime) {
            $newRecords->where('gps_data.date_time', '<=', $endDateTime);
        } else {
            $newRecords->whereDate('gps_data.date_time', $date);
        }

        $newRecords = $newRecords->orderBy('gps_data.date_time')->get();

        // Parse only the new records
        $this->data = [];
        $this->parseRecords($newRecords);

        // Step 5: Incremental analysis
        return $this->analyzeIncremental($cachedState, $includeDetails);
    }

    /**
     * Full load and analysis (used when no cache exists)
     */
    private function doFullLoadAndAnalysis(
        Tractor $tractor,
        Carbon $date,
        ?Carbon $startDateTime,
        ?Carbon $endDateTime,
        bool $includeDetails
    ): array {
        // Full data load
        if ($startDateTime && $endDateTime) {
            $prevPoint = $tractor->gpsData()
                ->select(['gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time', 'gps_data.imei'])
                ->toBase()
                ->where('gps_data.date_time', '<', $startDateTime)
                ->orderBy('gps_data.date_time', 'desc')
                ->first();

            $windowPoints = $tractor->gpsData()
                ->select(['gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time', 'gps_data.imei'])
                ->toBase()
                ->whereBetween('gps_data.date_time', [$startDateTime, $endDateTime])
                ->orderBy('gps_data.date_time')
                ->get();

            $gpsData = $prevPoint ? collect([$prevPoint])->merge($windowPoints) : $windowPoints;
        } else {
            $gpsData = $tractor->gpsData()
                ->select(['gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time', 'gps_data.imei'])
                ->toBase()
                ->whereDate('gps_data.date_time', $date)
                ->orderBy('gps_data.date_time')
                ->get();
        }

        $this->loadFromRecordsPreSorted($gpsData);

        // Full analysis
        $results = $this->analyze(null, null, $includeDetails);

        // Save to cache
        $this->saveStateToCache($tractor->id, $date);

        return $results;
    }

    /**
     * Analyze GPS data incrementally using cached state
     * NOTE: This method assumes data is already loaded. For the optimized flow,
     * use loadAndAnalyzeWithCache() instead.
     *
     * @param bool $includeDetails Whether to include movement/stoppage details
     * @return array Analysis results
     */
    public function analyzeWithCache(bool $includeDetails = false): array
    {
        if ($this->tractor === null || $this->date === null) {
            // Fallback to regular analysis if no tractor/date context
            return $this->analyze(null, null, $includeDetails);
        }

        $tractorId = $this->tractor->id;
        $date = $this->date;

        // Get cached state
        $cachedState = $this->cacheService->getState($tractorId, $date);

        if ($cachedState === null) {
            // No cache - do full analysis and cache results
            $results = $this->analyze(null, null, $includeDetails);
            $this->saveStateToCache($tractorId, $date);
            return $results;
        }

        // Check if we have new data to process
        if (empty($this->data)) {
            // No data loaded - return cached results
            return $this->buildResultsFromState($cachedState);
        }

        $lastDataTs = $this->data[count($this->data) - 1]['ts'];

        if ($lastDataTs <= $cachedState->lastProcessedTimestamp) {
            // No new data - return cached results
            return $this->buildResultsFromState($cachedState);
        }

        // Process incrementally from cached state
        $results = $this->analyzeIncremental($cachedState, $includeDetails);

        return $results;
    }

    /**
     * Analyze incrementally from a cached state
     * Only processes new points since the last cached state
     *
     * @param GpsAnalysisState $state Previous cached state
     * @param bool $includeDetails Whether to include movement/stoppage details
     * @return array Analysis results
     */
    public function analyzeIncremental(GpsAnalysisState $state, bool $includeDetails = false): array
    {
        if (empty($this->data)) {
            return $this->buildResultsFromState($state);
        }

        // Working time boundaries
        $wsTs = $this->workingStartTimestamp;
        $weTs = $this->workingEndTimestamp;
        $wsCarbon = $this->workingStartTime;
        $weCarbon = $this->workingEndTime;

        // Restore state from cache
        $movementDistance = $state->movementDistance;
        $movementDuration = $state->movementDuration;
        $stoppageDuration = $state->stoppageDuration;
        $stoppageDurationWhileOn = $state->stoppageDurationWhileOn;
        $stoppageDurationWhileOff = $state->stoppageDurationWhileOff;
        $stoppageCount = $state->stoppageCount;
        $maxSpeed = $state->maxSpeed;

        $isCurrentlyMoving = $state->isCurrentlyMoving;
        $isCurrentlyStopped = $state->isCurrentlyStopped;
        $segmentStartTimestamp = $state->segmentStartTimestamp;
        $movementSegmentDistance = $state->segmentDistance;

        // Segment start point info for details
        $segmentStartLat = $state->segmentStartLat;
        $segmentStartLon = $state->segmentStartLon;
        $segmentStartStatus = $state->segmentStartStatus;

        // Detection state
        $deviceOnTime = $state->deviceOnTime;
        $firstMovementTime = $state->firstMovementTime;
        $deviceOnTimeDetected = $state->deviceOnTimeDetected;
        $firstMovementTimeDetected = $state->firstMovementTimeDetected;
        $consecutiveMovementCount = $state->consecutiveMovementCount;
        $firstConsecutiveMovementTimestamp = $state->firstConsecutiveMovementTimestamp;

        // Previous point from cache
        $prevLatRad = $state->lastLatRad;
        $prevLonRad = $state->lastLonRad;
        $prevTs = $state->lastProcessedTimestamp;

        // Start time from cache or first point
        $startTime = $state->startTime ?? $this->data[0]['timestamp']->toTimeString();

        // Detail indices
        $movementDetailIndex = $state->movementDetailIndex;
        $stoppageDetailIndex = $state->stoppageDetailIndex;

        if ($includeDetails) {
            $this->movements = [];
            $this->stoppages = [];
        }

        $dataCount = count($this->data);
        $data = &$this->data;

        // Find starting index (skip already processed points)
        $startIndex = 0;
        for ($i = 0; $i < $dataCount; $i++) {
            if ($data[$i]['ts'] > $state->lastProcessedTimestamp) {
                $startIndex = $i;
                break;
            }
            $startIndex = $i + 1;
        }

        // If no new points, return cached results
        if ($startIndex >= $dataCount) {
            return $this->buildResultsFromState($state);
        }

        // Process new points
        for ($index = $startIndex; $index < $dataCount; $index++) {
            $point = &$data[$index];
            $speed = $point['speed'];
            $status = $point['status'];
            $ts = $point['ts'];

            // Track max speed
            if ($speed > $maxSpeed) {
                $maxSpeed = $speed;
            }

            // Track device_on_time (only if not already detected)
            if (!$deviceOnTimeDetected && $status === 1) {
                $deviceOnTime = $point['timestamp']->toTimeString();
                $deviceOnTimeDetected = true;
            }

            // Inline movement/stoppage checks
            $isMoving = ($status === 1 && $speed > 0);
            $isStopped = ($speed === 0);

            // Track consecutive movements for firstMovementTime (only if not already detected)
            if (!$firstMovementTimeDetected) {
                if ($isMoving) {
                    if ($consecutiveMovementCount === 0) {
                        $firstConsecutiveMovementTimestamp = $ts;
                    }
                    $consecutiveMovementCount++;
                    if ($consecutiveMovementCount === 3) {
                        // Find the timestamp for the first consecutive movement
                        $firstMovementTime = Carbon::createFromTimestamp($firstConsecutiveMovementTimestamp)->toTimeString();
                        $firstMovementTimeDetected = true;
                    }
                } else {
                    $consecutiveMovementCount = 0;
                    $firstConsecutiveMovementTimestamp = null;
                }
            }

            // First point in incremental batch
            if ($prevTs === null || $prevTs === 0) {
                if ($isStopped) {
                    $isCurrentlyStopped = true;
                    $segmentStartTimestamp = $ts;
                    $segmentStartLat = $point['lat'];
                    $segmentStartLon = $point['lon'];
                    $segmentStartStatus = $status;
                } elseif ($isMoving) {
                    $isCurrentlyMoving = true;
                    $segmentStartTimestamp = $ts;
                    $segmentStartLat = $point['lat'];
                    $segmentStartLon = $point['lon'];
                    $movementSegmentDistance = 0.0;
                }
                $prevLatRad = $point['lat_rad'];
                $prevLonRad = $point['lon_rad'];
                $prevTs = $ts;
                continue;
            }

            // Calculate time difference
            $timeDiff = $this->calcDurationFast($prevTs, $ts, $wsTs, $weTs);

            // Transition: Moving -> Stopped
            if ($isStopped && $isCurrentlyMoving) {
                $distance = $this->haversineDistanceRad($prevLatRad, $prevLonRad, $point['lat_rad'], $point['lon_rad']);
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;

                if ($includeDetails && $segmentStartTimestamp !== null) {
                    $movementDetailIndex++;
                    $duration = $this->calcDurationFast($segmentStartTimestamp, $ts, $wsTs, $weTs);
                    $this->movements[] = [
                        'index' => $movementDetailIndex,
                        'start_time' => Carbon::createFromTimestamp($segmentStartTimestamp)->toTimeString(),
                        'end_time' => $point['timestamp']->toTimeString(),
                        'duration_seconds' => $duration,
                        'duration_formatted' => to_time_format($duration),
                        'distance_km' => round($movementSegmentDistance, 3),
                        'distance_meters' => round($movementSegmentDistance * 1000, 2),
                        'start_location' => [
                            'latitude' => $segmentStartLat,
                            'longitude' => $segmentStartLon,
                        ],
                        'end_location' => [
                            'latitude' => $point['lat'],
                            'longitude' => $point['lon'],
                        ],
                        'avg_speed' => $duration > 0 ? round(($movementSegmentDistance / $duration) * 3600, 2) : 0,
                    ];
                }

                $isCurrentlyMoving = false;
                $isCurrentlyStopped = true;
                $segmentStartTimestamp = $ts;
                $segmentStartLat = $point['lat'];
                $segmentStartLon = $point['lon'];
                $segmentStartStatus = $status;
                $movementSegmentDistance = 0.0;
            }
            // Transition: Stopped -> Moving
            elseif ($isMoving && $isCurrentlyStopped) {
                $tempDuration = $this->calcDurationFast($segmentStartTimestamp ?? $ts, $ts, $wsTs, $weTs);

                // Simplified on/off calculation for incremental
                $tempDurationOn = 0;
                $tempDurationOff = 0;
                if ($segmentStartStatus === 1) {
                    $tempDurationOn = $tempDuration;
                } else {
                    $tempDurationOff = $tempDuration;
                }

                $isIgnored = $tempDuration < 60;

                if ($includeDetails && !$isIgnored && $segmentStartTimestamp !== null) {
                    $stoppageDetailIndex++;
                    $this->stoppages[] = [
                        'index' => $stoppageDetailIndex,
                        'start_time' => Carbon::createFromTimestamp($segmentStartTimestamp)->toTimeString(),
                        'end_time' => $point['timestamp']->toTimeString(),
                        'duration_seconds' => $tempDuration,
                        'duration_formatted' => to_time_format($tempDuration),
                        'location' => [
                            'latitude' => $segmentStartLat,
                            'longitude' => $segmentStartLon,
                        ],
                        'status' => $segmentStartStatus === 1 ? 'on' : 'off',
                        'ignored' => false,
                    ];
                }

                if ($tempDuration >= 60) {
                    $stoppageCount++;
                    $stoppageDuration += $tempDuration;
                    $stoppageDurationWhileOn += $tempDurationOn;
                    $stoppageDurationWhileOff += $tempDurationOff;
                } else {
                    $movementDuration += $tempDuration;
                }

                $isCurrentlyStopped = false;
                $isCurrentlyMoving = true;
                $segmentStartTimestamp = $ts;
                $segmentStartLat = $point['lat'];
                $segmentStartLon = $point['lon'];
                $movementSegmentDistance = 0.0;
            }
            // Continue moving
            elseif ($isMoving && $isCurrentlyMoving) {
                $distance = $this->haversineDistanceRad($prevLatRad, $prevLonRad, $point['lat_rad'], $point['lon_rad']);
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;
            }
            // First stoppage
            elseif ($isStopped && !$isCurrentlyStopped && !$isCurrentlyMoving) {
                $isCurrentlyStopped = true;
                $segmentStartTimestamp = $ts;
                $segmentStartLat = $point['lat'];
                $segmentStartLon = $point['lon'];
                $segmentStartStatus = $status;
            }

            $prevLatRad = $point['lat_rad'];
            $prevLonRad = $point['lon_rad'];
            $prevTs = $ts;
        }

        // Get last point for state
        $lastPoint = $data[$dataCount - 1];

        // Update state and cache
        $newState = new GpsAnalysisState(
            lastProcessedTimestamp: $lastPoint['ts'],
            lastProcessedIndex: $dataCount - 1,
            deviceOnTime: $deviceOnTime,
            firstMovementTime: $firstMovementTime,
            deviceOnTimeDetected: $deviceOnTimeDetected,
            firstMovementTimeDetected: $firstMovementTimeDetected,
            movementDistance: $movementDistance,
            movementDuration: $movementDuration,
            stoppageDuration: $stoppageDuration,
            stoppageDurationWhileOn: $stoppageDurationWhileOn,
            stoppageDurationWhileOff: $stoppageDurationWhileOff,
            stoppageCount: $stoppageCount,
            maxSpeed: $maxSpeed,
            isCurrentlyMoving: $isCurrentlyMoving,
            isCurrentlyStopped: $isCurrentlyStopped,
            segmentStartIndex: null,
            segmentStartTimestamp: $segmentStartTimestamp,
            segmentDistance: $movementSegmentDistance,
            consecutiveMovementCount: $consecutiveMovementCount,
            firstConsecutiveMovementTimestamp: $firstConsecutiveMovementTimestamp,
            lastLat: $lastPoint['lat'],
            lastLon: $lastPoint['lon'],
            lastLatRad: $lastPoint['lat_rad'],
            lastLonRad: $lastPoint['lon_rad'],
            lastSpeed: $lastPoint['speed'],
            lastStatus: $lastPoint['status'],
            startTime: $startTime,
            segmentStartLat: $segmentStartLat,
            segmentStartLon: $segmentStartLon,
            segmentStartStatus: $segmentStartStatus,
            movementDetailIndex: $movementDetailIndex,
            stoppageDetailIndex: $stoppageDetailIndex,
        );

        // Save to cache
        if ($this->tractor !== null && $this->date !== null) {
            $this->cacheService->saveState($this->tractor->id, $this->date, $newState);
        }

        // Build and return results
        $averageSpeed = $movementDuration > 0 ? (int)($movementDistance * 3600 / $movementDuration) : 0;

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
            'start_time' => $startTime,
            'latest_status' => $lastPoint['status'],
            'average_speed' => $averageSpeed,
        ];

        return $this->results;
    }

    /**
     * Build results array from cached state (no processing needed)
     */
    private function buildResultsFromState(GpsAnalysisState $state): array
    {
        $averageSpeed = $state->movementDuration > 0
            ? (int)($state->movementDistance * 3600 / $state->movementDuration)
            : 0;

        return [
            'movement_distance_km' => round($state->movementDistance, 1),
            'movement_distance_meters' => round($state->movementDistance * 1000, 2),
            'movement_duration_seconds' => $state->movementDuration,
            'movement_duration_formatted' => to_time_format($state->movementDuration),
            'stoppage_duration_seconds' => $state->stoppageDuration,
            'stoppage_duration_formatted' => to_time_format($state->stoppageDuration),
            'stoppage_duration_while_on_seconds' => $state->stoppageDurationWhileOn,
            'stoppage_duration_while_on_formatted' => to_time_format($state->stoppageDurationWhileOn),
            'stoppage_duration_while_off_seconds' => $state->stoppageDurationWhileOff,
            'stoppage_duration_while_off_formatted' => to_time_format($state->stoppageDurationWhileOff),
            'stoppage_count' => $state->stoppageCount,
            'device_on_time' => $state->deviceOnTime,
            'first_movement_time' => $state->firstMovementTime,
            'start_time' => $state->startTime,
            'latest_status' => $state->lastStatus,
            'average_speed' => $averageSpeed,
        ];
    }

    /**
     * Save current analysis state to cache after full analysis
     */
    private function saveStateToCache(int $tractorId, Carbon $date): void
    {
        if (empty($this->data)) {
            return;
        }

        $dataCount = count($this->data);
        $lastPoint = $this->data[$dataCount - 1];
        $firstPoint = $this->data[0];

        // Extract values from results
        $results = $this->results;

        $state = new GpsAnalysisState(
            lastProcessedTimestamp: $lastPoint['ts'],
            lastProcessedIndex: $dataCount - 1,
            deviceOnTime: $results['device_on_time'] ?? null,
            firstMovementTime: $results['first_movement_time'] ?? null,
            deviceOnTimeDetected: $results['device_on_time'] !== null,
            firstMovementTimeDetected: $results['first_movement_time'] !== null,
            movementDistance: (float)str_replace(',', '', $results['movement_distance_km']),
            movementDuration: $results['movement_duration_seconds'],
            stoppageDuration: $results['stoppage_duration_seconds'],
            stoppageDurationWhileOn: $results['stoppage_duration_while_on_seconds'],
            stoppageDurationWhileOff: $results['stoppage_duration_while_off_seconds'],
            stoppageCount: $results['stoppage_count'],
            maxSpeed: 0,
            isCurrentlyMoving: $lastPoint['status'] === 1 && $lastPoint['speed'] > 0,
            isCurrentlyStopped: $lastPoint['speed'] === 0,
            segmentStartIndex: null,
            segmentStartTimestamp: $lastPoint['ts'],
            segmentDistance: 0.0,
            consecutiveMovementCount: 0,
            firstConsecutiveMovementTimestamp: null,
            lastLat: $lastPoint['lat'],
            lastLon: $lastPoint['lon'],
            lastLatRad: $lastPoint['lat_rad'],
            lastLonRad: $lastPoint['lon_rad'],
            lastSpeed: $lastPoint['speed'],
            lastStatus: $lastPoint['status'],
            startTime: $results['start_time'] ?? $firstPoint['timestamp']->toTimeString(),
            segmentStartLat: $lastPoint['lat'],
            segmentStartLon: $lastPoint['lon'],
            segmentStartStatus: $lastPoint['status'],
            movementDetailIndex: count($this->movements),
            stoppageDetailIndex: count($this->stoppages),
        );

        $this->cacheService->saveState($tractorId, $date, $state);
    }

    /**
     * Analyze with caching - lightweight version (no details)
     */
    public function analyzeLightWithCache(): array
    {
        return $this->analyzeWithCache(false);
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
