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
     */
    private function parseRecords($data): void
    {
        $this->data = [];

        foreach ($data as $record) {
            if (is_object($record)) {
                $dateTime = $record->date_time;
                $ts = $dateTime instanceof Carbon
                    ? $dateTime->timestamp
                    : Carbon::parse($dateTime)->timestamp;

                [$lat, $lon] = $this->parseCoordinate($record->coordinate);

                // Skip records with invalid coordinates (0,0)
                if ($lat == 0 && $lon == 0) {
                    continue;
                }

                $latRad = deg2rad($lat);
                $lonRad = deg2rad($lon);

                $this->data[] = [
                    $lat,      // 0: lat
                    $lon,      // 1: lon
                    $latRad,   // 2: lat_rad
                    $lonRad,   // 3: lon_rad
                    $ts,       // 4: timestamp (unix)
                    (int)$record->speed,  // 5: speed
                    (int)$record->status, // 6: status
                ];
            } else {
                [$lat, $lon] = $this->parseCoordinate($record['coordinate'] ?? null);

                // Skip records with invalid coordinates (0,0)
                if ($lat == 0 && $lon == 0) {
                    continue;
                }

                $dateTime = $record['date_time'];
                $ts = $dateTime instanceof Carbon
                    ? $dateTime->timestamp
                    : ($dateTime ? Carbon::parse($dateTime)->timestamp : time());

                $this->data[] = [
                    $lat,
                    $lon,
                    deg2rad($lat),
                    deg2rad($lon),
                    $ts,
                    (int)($record['speed'] ?? 0),
                    (int)($record['status'] ?? 0),
                ];
            }
        }
    }

    /**
     * Parse coordinate from various formats (JSON string, comma-separated string, or array)
     */
    private function parseCoordinate($coordinate): array
    {
        $lat = 0.0;
        $lon = 0.0;

        if ($coordinate === null) {
            return [$lat, $lon];
        }

        if (is_string($coordinate)) {
            $firstChar = $coordinate[0] ?? '';
            if ($firstChar === '[') {
                // JSON array format: [lat,lon]
                $decoded = json_decode($coordinate, true);
                if ($decoded && is_array($decoded)) {
                    $lat = (float)($decoded[0] ?? 0);
                    $lon = (float)($decoded[1] ?? 0);
                }
            } else {
                // Comma-separated format: "lat,lon"
                $parts = explode(',', $coordinate, 2);
                if (count($parts) === 2) {
                    $lat = (float)trim($parts[0]);
                    $lon = (float)trim($parts[1]);
                }
            }
        } elseif (is_array($coordinate)) {
            $lat = (float)($coordinate[0] ?? 0);
            $lon = (float)($coordinate[1] ?? 0);
        }

        return [$lat, $lon];
    }

    /**
     * Analyze GPS data and calculate all metrics
     *
     * Movement = speed > 0
     * Stoppage = speed == 0 (stoppages < 60s counted as movement)
     * firstMovementTime = timestamp when 3 consecutive active movements detected (status=1 && speed>0)
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

        $wsTs = $workingStartTime?->timestamp ?? $this->workingStartTimestamp;
        $weTs = $workingEndTime?->timestamp ?? $this->workingEndTimestamp;

        // Pre-normalize polygon once if provided
        $normalizedPolygon = [];
        $hasPolygon = !empty($polygon);
        if ($hasPolygon) {
            $normalizedPolygon = $this->normalizePolygon($polygon);
        }

        // Counters
        $movementDistance = 0.0;
        $movementDuration = 0;
        $stoppageDuration = 0;
        $stoppageDurationWhileOn = 0;
        $stoppageDurationWhileOff = 0;
        $stoppageCount = 0;

        // State
        $isCurrentlyStopped = false;
        $isCurrentlyMoving = false;
        $stoppageStartIndex = -1;

        // Activation tracking
        $deviceOnTs = null;
        $firstMovementTs = null;
        $consecutiveMovementCount = 0;
        $firstConsecutiveMovementIndex = -1;

        // Previous point cache
        $prevLatRad = 0.0;
        $prevLonRad = 0.0;
        $prevTs = -1;

        $data = &$this->data;

        for ($i = 0; $i < $dataCount; $i++) {
            $point = &$data[$i];
            $lon = $point[1];
            $lat = $point[0];

            $latRad = $point[2];
            $lonRad = $point[3];
            $ts = $point[4];
            $speed = $point[5];
            $status = $point[6];

            // Track device on time (first status=1)
            if ($deviceOnTs === null && $status === 1) {
                $deviceOnTs = $ts;
            }

            // Movement: speed > 0 (status=1 required only for first_movement_time tracking)
            // Stoppage: speed == 0
            $isMoving = ($speed > 0);
            $isStopped = ($speed === 0);
            $isActiveMovement = ($status === 1 && $speed > 0);

            // Track consecutive active movements for firstMovementTime (requires status=1)
            if ($firstMovementTs === null) {
                if ($isActiveMovement) {
                    if ($consecutiveMovementCount === 0) {
                        $firstConsecutiveMovementIndex = $i;
                    }
                    $consecutiveMovementCount++;
                    if ($consecutiveMovementCount === self::CONSECUTIVE_MOVEMENTS_FOR_FIRST_MOVEMENT) {
                        $firstMovementTs = $data[$firstConsecutiveMovementIndex][4];
                    }
                } else {
                    $consecutiveMovementCount = 0;
                    $firstConsecutiveMovementIndex = -1;
                }
            }

            if ($prevTs < 0) {
                // First valid point
                if ($isStopped) {
                    $isCurrentlyStopped = true;
                    $stoppageStartIndex = $i;
                } elseif ($isMoving) {
                    $isCurrentlyMoving = true;
                }
                $prevLatRad = $latRad;
                $prevLonRad = $lonRad;
                $prevTs = $ts;
                continue;
            }

            $timeDiff = $this->calcDuration($prevTs, $ts, $wsTs, $weTs);

            // State transitions
            if ($isStopped && $isCurrentlyMoving) {
                // Moving -> Stopped
                $movementDistance += $this->haversineRad($prevLatRad, $prevLonRad, $latRad, $lonRad);
                $movementDuration += $timeDiff;

                $isCurrentlyMoving = false;
                $isCurrentlyStopped = true;
                $stoppageStartIndex = $i;
            } elseif ($isMoving && $isCurrentlyStopped) {
                // Stopped -> Moving
                $stoppageStartTs = $data[$stoppageStartIndex][4];
                $tempDuration = $this->calcDuration($stoppageStartTs, $ts, $wsTs, $weTs);

                if ($tempDuration >= self::MIN_STOPPAGE_DURATION_SECONDS) {
                    $stoppageCount++;
                    $stoppageDuration += $tempDuration;
                    [$onDur, $offDur] = $this->calcStoppageOnOff($stoppageStartIndex, $i, $wsTs, $weTs);
                    $stoppageDurationWhileOn += $onDur;
                    $stoppageDurationWhileOff += $offDur;
                } else {
                    $movementDuration += $tempDuration;
                }

                $isCurrentlyStopped = false;
                $isCurrentlyMoving = true;
            } elseif ($isMoving && $isCurrentlyMoving) {
                // Continue moving
                $movementDistance += $this->haversineRad($prevLatRad, $prevLonRad, $latRad, $lonRad);
                $movementDuration += $timeDiff;
            } elseif ($isStopped && !$isCurrentlyStopped && !$isCurrentlyMoving) {
                $isCurrentlyStopped = true;
                $stoppageStartIndex = $i;
            }

            $prevLatRad = $latRad;
            $prevLonRad = $lonRad;
            $prevTs = $ts;
        }

        // Handle final state
        if ($isCurrentlyStopped && $stoppageStartIndex >= 0) {
            $stoppageStartTs = $data[$stoppageStartIndex][4];
            $lastTs = $data[$dataCount - 1][4];
            $tempDuration = $this->calcDuration($stoppageStartTs, $lastTs, $wsTs, $weTs);

            if ($tempDuration >= self::MIN_STOPPAGE_DURATION_SECONDS) {
                $stoppageCount++;
                $stoppageDuration += $tempDuration;
                [$onDur, $offDur] = $this->calcStoppageOnOff($stoppageStartIndex, $dataCount - 1, $wsTs, $weTs);
                $stoppageDurationWhileOn += $onDur;
                $stoppageDurationWhileOff += $offDur;
            } else {
                $movementDuration += $tempDuration;
            }
        }

        $averageSpeed = $movementDuration > 0
            ? (int)($movementDistance * 3600 / $movementDuration)
            : 0;

        $lastPoint = $data[$dataCount - 1];

        $this->results = [
            'movement_distance_km' => round($movementDistance, 1),
            'movement_distance_meters' => round($movementDistance * 1000, 2),
            'movement_duration_seconds' => $movementDuration,
            'movement_duration_formatted' => $this->formatTime($movementDuration),
            'stoppage_duration_seconds' => $stoppageDuration,
            'stoppage_duration_formatted' => $this->formatTime($stoppageDuration),
            'stoppage_duration_while_on_seconds' => $stoppageDurationWhileOn,
            'stoppage_duration_while_on_formatted' => $this->formatTime($stoppageDurationWhileOn),
            'stoppage_duration_while_off_seconds' => $stoppageDurationWhileOff,
            'stoppage_duration_while_off_formatted' => $this->formatTime($stoppageDurationWhileOff),
            'stoppage_count' => $stoppageCount,
            'device_on_time' => $deviceOnTs !== null ? $this->formatTimestamp($deviceOnTs) : null,
            'first_movement_time' => $firstMovementTs !== null ? $this->formatTimestamp($firstMovementTs) : null,
            'latest_status' => $lastPoint[6],
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

        $a = sin($dLat * 0.5) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($dLon * 0.5) ** 2;

        return 12742 * atan2(sqrt($a), sqrt(1 - $a)); // 2 * 6371 = 12742
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

        $startTs = $data[$startIdx][4];

        // Handle stoppage starting before working window
        if ($wsTs !== null && $startTs < $wsTs) {
            for ($i = $startIdx + 1; $i <= $endIdx; $i++) {
                if ($data[$i][4] >= $wsTs) {
                    $firstIdx = $i;
                    $td = $this->calcDuration($wsTs, $data[$i][4], $wsTs, $weTs);
                    if ($data[$startIdx][6] === 1) {
                        $durationOn += $td;
                    } else {
                        $durationOff += $td;
                    }
                    break;
                }
            }
            if ($firstIdx === $startIdx) {
                $td = $this->calcDuration($wsTs, $data[$endIdx][4], $wsTs, $weTs);
                if ($data[$startIdx][6] === 1) {
                    $durationOn += $td;
                } else {
                    $durationOff += $td;
                }
            }
        }

        // Sum duration per segment based on status
        for ($i = $firstIdx + 1; $i <= $endIdx; $i++) {
            $td = $this->calcDuration($data[$i - 1][4], $data[$i][4], $wsTs, $weTs);
            if ($data[$i][6] === 1) {
                $durationOn += $td;
            } else {
                $durationOff += $td;
            }
        }

        // Normalize to match total duration
        $totalDuration = $this->calcDuration($data[$startIdx][4], $data[$endIdx][4], $wsTs, $weTs);
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
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
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
