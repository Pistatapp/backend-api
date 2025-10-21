<?php

namespace App\Services;

use Carbon\Carbon;

class GpsDataAnalyzer
{
    private array $data = [];
    private array $results = [];
    private array $movements = [];
    private array $stoppages = [];
    private bool $analyzed = false;

    /**
     * Parse a GPS data record from Hooshnic format
     * Format: +Hooshnic:V1.06,LAT,LON,ALT,DATE,TIME,SPEED,HEADING,STATUS,SATELLITES,FIX,IMEI
     */
    private function parseRecord(string $line): ?array
    {
        // Extract JSON from line
        if (!preg_match('/"data":"(.+?)"/', $line, $matches)) {
            return null;
        }

        $data = $matches[1];
        $parts = explode(',', $data);

        if (count($parts) < 12) {
            return null;
        }

        // Parse coordinates in NMEA format: DDMM.MMMM (degrees and decimal minutes)
        // Example: 3556.42915 = 35° 56.42915' = 35 + (56.42915 / 60) degrees
        $latRaw = floatval($parts[1]);
        $lonRaw = floatval($parts[2]);

        // Convert DDMM.MMMM to DD.DDDDDD
        $latDegrees = floor($latRaw / 100);
        $latMinutes = $latRaw - ($latDegrees * 100);
        $latitude = $latDegrees + ($latMinutes / 60);

        $lonDegrees = floor($lonRaw / 100);
        $lonMinutes = $lonRaw - ($lonDegrees * 100);
        $longitude = $lonDegrees + ($lonMinutes / 60);

        $altitude = intval($parts[3]);

        // Parse date and time using ymdHis format (same as ParseDataService)
        // Format: DDMMYY HHMMSS concatenated as ymdHis
        // Example: 251020 083042 = 2025-10-20 08:30:42
        $dateStr = $parts[4]; // DDMMYY but parsed as ymd
        $timeStr = $parts[5]; // HHMMSS but parsed as His

        $timestamp = Carbon::createFromFormat('ymdHis', $dateStr . $timeStr)
            ->addHours(3)
            ->addMinutes(30); // Iran timezone adjustment (UTC+3:30)

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'altitude' => $altitude,
            'timestamp' => $timestamp,
            'speed' => intval($parts[6]), // km/h
            'heading' => intval($parts[7]), // degrees
            'status' => intval($parts[8]), // 0=off, 1=on
            'satellites' => intval($parts[9]),
            'fix' => intval($parts[10]),
            'imei' => $parts[11],
        ];
    }

    /**
     * Calculate distance between two GPS points using Haversine formula
     * Returns distance in kilometers
     */
    private function calculateDistance(array $point1, array $point2): float
    {
        $earthRadius = 6371; // km

        $lat1 = deg2rad($point1['latitude']);
        $lon1 = deg2rad($point1['longitude']);
        $lat2 = deg2rad($point2['latitude']);
        $lon2 = deg2rad($point2['longitude']);

        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1) * cos($lat2) *
             sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Load and parse GPS data from file
     */
    public function loadFromFile(string $filePath): self
    {
        $this->data = [];
        $this->analyzed = false; // Reset analysis flag
        $this->movements = [];
        $this->stoppages = [];
        $this->results = [];

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Handle multiple JSON objects in one line
            preg_match_all('/\{"data":"[^}]+"\}/', $line, $matches);

            foreach ($matches[0] as $jsonStr) {
                $record = $this->parseRecord($jsonStr);
                if ($record) {
                    $this->data[] = $record;
                }
            }
        }

        // Sort by timestamp
        usort($this->data, function ($a, $b) {
            return $a['timestamp']->timestamp <=> $b['timestamp']->timestamp;
        });

        return $this;
    }

    /**
     * Load GPS data from array
     */
    public function loadFromArray(array $data): self
    {
        $this->data = $data;
        $this->analyzed = false; // Reset analysis flag
        $this->movements = [];
        $this->stoppages = [];
        $this->results = [];

        // Sort by timestamp
        usort($this->data, function ($a, $b) {
            return $a['timestamp']->timestamp <=> $b['timestamp']->timestamp;
        });

        return $this;
    }

    /**
     * Analyze GPS data and calculate all metrics
     * Note: Speed > 2 km/h is considered movement, speed <= 2 km/h is stopped
     * Note: Stoppages less than 60 seconds are considered as movements
     * Note: First stoppage point in batch = last movement point
     * Note: First movement point in batch = last stoppage point
     */
    public function analyze(): array
    {
        if (empty($this->data)) {
            return $this->getEmptyResults();
        }

        // Return cached results if already analyzed
        if ($this->analyzed) {
            return $this->results;
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

        $dataCount = count($this->data);

        foreach ($this->data as $index => $currentPoint) {
            // Track activation times inline (optimization: single pass)
            if ($deviceOnTime === null && $currentPoint['status'] == 1) {
                $deviceOnTime = $currentPoint['timestamp']->toDateTimeString();
            }
            if ($firstMovementTime === null && $currentPoint['status'] == 1 && $currentPoint['speed'] > 2) {
                $firstMovementTime = $currentPoint['timestamp']->toDateTimeString();
            }

            if ($previousPoint === null) {
                // First point
                if ($currentPoint['speed'] <= 2) {
                    $isCurrentlyStopped = true;
                    $stoppageStartIndex = $index;
                } else {
                    $isCurrentlyMoving = true;
                    $movementStartIndex = $index;
                    $movementSegmentDistance = 0;
                }
                $previousPoint = $currentPoint;
                continue;
            }

            $timeDiff = $currentPoint['timestamp']->timestamp - $previousPoint['timestamp']->timestamp;
            $isStopped = $currentPoint['speed'] <= 2;
            $isMoving = $currentPoint['speed'] > 2;

            // Transition: Moving -> Stopped
            if ($isStopped && $isCurrentlyMoving) {
                // Add transition to movement
                $distance = $this->calculateDistance($previousPoint, $currentPoint);
                $movementSegmentDistance += $distance;
                $movementDistance += $distance;
                $movementDuration += $timeDiff;

                // Save movement detail
                $movementDetailIndex++;
                $duration = $currentPoint['timestamp']->timestamp - $this->data[$movementStartIndex]['timestamp']->timestamp;
                $this->movements[] = [
                    'index' => $movementDetailIndex,
                    'start_time' => $this->data[$movementStartIndex]['timestamp']->toDateTimeString(),
                    'end_time' => $currentPoint['timestamp']->toDateTimeString(),
                    'duration_seconds' => $duration,
                    'duration_formatted' => $this->formatDuration($duration),
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
                    'start_time' => $this->data[$stoppageStartIndex]['timestamp']->toDateTimeString(),
                    'end_time' => $currentPoint['timestamp']->toDateTimeString(),
                    'duration_seconds' => $tempDuration,
                    'duration_formatted' => $this->formatDuration($tempDuration),
                    'location' => [
                        'latitude' => $this->data[$stoppageStartIndex]['latitude'],
                        'longitude' => $this->data[$stoppageStartIndex]['longitude'],
                    ],
                    'status' => $this->data[$stoppageStartIndex]['status'] == 1 ? 'on' : 'off',
                    'ignored' => $isIgnored,
                ];

                // Update totals
                if ($tempDuration >= 60) {
                    $stoppageCount++;
                    $stoppageDuration += $tempDuration;
                    $stoppageDurationWhileOn += $tempDurationOn;
                    $stoppageDurationWhileOff += $tempDurationOff;
                } else {
                    $ignoredStoppageCount++;
                    $ignoredStoppageDuration += $tempDuration;
                    $movementDuration += $tempDuration;
                }

                $isCurrentlyStopped = false;
                $isCurrentlyMoving = true;
                $movementStartIndex = $index;
                $movementSegmentDistance = 0;
            }
            // Continue moving
            elseif ($isMoving && $isCurrentlyMoving) {
                $distance = $this->calculateDistance($previousPoint, $currentPoint);
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
            $movementDetailIndex++;
            $duration = $previousPoint['timestamp']->timestamp - $this->data[$movementStartIndex]['timestamp']->timestamp;
            $this->movements[] = [
                'index' => $movementDetailIndex,
                'start_time' => $this->data[$movementStartIndex]['timestamp']->toDateTimeString(),
                'end_time' => $previousPoint['timestamp']->toDateTimeString(),
                'duration_seconds' => $duration,
                'duration_formatted' => $this->formatDuration($duration),
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
                'start_time' => $this->data[$stoppageStartIndex]['timestamp']->toDateTimeString(),
                'end_time' => $previousPoint['timestamp']->toDateTimeString(),
                'duration_seconds' => $tempDuration,
                'duration_formatted' => $this->formatDuration($tempDuration),
                'location' => [
                    'latitude' => $this->data[$stoppageStartIndex]['latitude'],
                    'longitude' => $this->data[$stoppageStartIndex]['longitude'],
                ],
                'status' => $this->data[$stoppageStartIndex]['status'] == 1 ? 'on' : 'off',
                'ignored' => $isIgnored,
            ];

            if ($tempDuration >= 60) {
                $stoppageCount++;
                $stoppageDuration += $tempDuration;
                $stoppageDurationWhileOn += $tempDurationOn;
                $stoppageDurationWhileOff += $tempDurationOff;
            } else {
                $ignoredStoppageCount++;
                $ignoredStoppageDuration += $tempDuration;
                $movementDuration += $tempDuration;
            }
        }

        $this->results = [
            'movement_distance_km' => round($movementDistance, 3),
            'movement_distance_meters' => round($movementDistance * 1000, 2),
            'movement_duration_seconds' => $movementDuration,
            'movement_duration_formatted' => $this->formatDuration($movementDuration),
            'stoppage_duration_seconds' => $stoppageDuration,
            'stoppage_duration_formatted' => $this->formatDuration($stoppageDuration),
            'stoppage_duration_while_on_seconds' => $stoppageDurationWhileOn,
            'stoppage_duration_while_on_formatted' => $this->formatDuration($stoppageDurationWhileOn),
            'stoppage_duration_while_off_seconds' => $stoppageDurationWhileOff,
            'stoppage_duration_while_off_formatted' => $this->formatDuration($stoppageDurationWhileOff),
            'stoppage_count' => $stoppageCount,
            'ignored_stoppage_count' => $ignoredStoppageCount,
            'ignored_stoppage_duration_seconds' => $ignoredStoppageDuration,
            'ignored_stoppage_duration_formatted' => $this->formatDuration($ignoredStoppageDuration),
            'device_on_time' => $deviceOnTime,
            'first_movement_time' => $firstMovementTime,
            'total_records' => $dataCount,
            'start_time' => $this->data[0]['timestamp']->toDateTimeString(),
            'end_time' => $this->data[$dataCount - 1]['timestamp']->toDateTimeString(),
        ];

        $this->analyzed = true; // Mark as analyzed for caching
        return $this->results;
    }


    /**
     * Format duration in seconds to human-readable format
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
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
        ];
    }

    /**
     * Get detailed stoppage information (optimized - returns cached data)
     * Includes all stoppages with 'ignored' flag for those < 60 seconds
     * Note: Speed <= 2 km/h is considered stopped
     * Note: First movement point in batch = last stoppage point
     */
    public function getStoppageDetails(): array
    {
        // Auto-analyze if not done yet
        if (!$this->analyzed) {
            $this->analyze();
        }

        return $this->stoppages;
    }

    /**
     * Get detailed movement information (optimized - returns cached data)
     * Note: Speed > 2 km/h is considered movement
     * Note: First stoppage point in batch = last movement point
     */
    public function getMovementDetails(): array
    {
        // Auto-analyze if not done yet
        if (!$this->analyzed) {
            $this->analyze();
        }

        return $this->movements;
    }

    /**
     * Get combined movement and stoppage details in chronological order (optimized)
     */
    public function getChronologicalDetails(): array
    {
        // Auto-analyze if not done yet
        if (!$this->analyzed) {
            $this->analyze();
        }

        // Combine cached arrays with type identifier
        $combined = [];

        foreach ($this->movements as $movement) {
            $combined[] = array_merge($movement, ['type' => 'movement']);
        }

        foreach ($this->stoppages as $stoppage) {
            $combined[] = array_merge($stoppage, ['type' => 'stoppage']);
        }

        // Sort by start time (using timestamp for faster comparison)
        usort($combined, function ($a, $b) {
            return strtotime($a['start_time']) <=> strtotime($b['start_time']);
        });

        return $combined;
    }

    /**
     * Get results
     */
    public function getResults(): array
    {
        return $this->results;
    }
}

