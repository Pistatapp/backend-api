<?php

namespace App\Services;

use App\Models\Tractor;
use App\Http\Resources\PointsResource;
use App\Services\GpsDataAnalyzer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TractorPathService
{
    private const MAX_POINTS_PER_PATH = 5000;
    private const MAX_GPS_DATA_POINTS = 50000; // Maximum GPS points to process per day to prevent memory exhaustion

    public function __construct(
        private GpsDataAnalyzer $gpsDataAnalyzer
    ) {}

    /**
     * Retrieves the tractor movement path for a specific date using GPS data analysis.
     * Optimized for performance without caching for real-time updates.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function getTractorPath(Tractor $tractor, Carbon $date)
    {
        try {
            // Check if tractor has GPS device
            if (!$tractor->gpsDevice) {
                return PointsResource::collection(collect());
            }

            // Get optimized GPS data for the specified date
            $gpsData = $this->getOptimizedGpsData($tractor, $date);

            // If no points exist for the specified date, fetch the last point from the previous date
            if ($gpsData->isEmpty()) {
                $lastPointFromPreviousDate = $this->getLastPointFromPreviousDate($tractor, $date);

                if ($lastPointFromPreviousDate) {
                    // Convert the single point to PointsResource format
                    $formattedPoint = $this->convertSinglePointToResource($lastPointFromPreviousDate);
                    return PointsResource::collection(collect([$formattedPoint]));
                }

                return PointsResource::collection(collect());
            }

            // Smooth out GPS errors (single-point anomalies) and extract path in optimized way
            $smoothedGpsData = $this->smoothGpsErrors($gpsData);

            // Free memory from original GPS data collection
            unset($gpsData);

            // Extract movement points and find first movement point ID in single pass
            [$pathPoints, $firstMovementPointId] = $this->extractMovementPointsOptimized($smoothedGpsData);

            // Free memory from smoothed GPS data
            unset($smoothedGpsData);

            // Convert to PointsResource format
            $formattedPathPoints = $this->convertToPathPointsOptimized($pathPoints, $firstMovementPointId);

            // Free memory from path points
            unset($pathPoints);

            return PointsResource::collection($formattedPathPoints);

        } catch (\Exception $e) {
            Log::error('Failed to get tractor path', [
                'tractor_id' => $tractor->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);

            return PointsResource::collection(collect());
        }
    }

    /**
     * Get optimized GPS data for the specified date
     * Optimized for memory efficiency by selecting only necessary columns
     * and implementing intelligent sampling for large datasets
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    private function getOptimizedGpsData(Tractor $tractor, Carbon $date): \Illuminate\Support\Collection
    {
        // Select only necessary columns to reduce memory footprint
        $baseQuery = $tractor->gpsData()
            ->select('id', 'coordinate', 'speed', 'status', 'directions', 'date_time')
            ->whereDate('date_time', $date)
            ->orderBy('date_time');

        // First, try to get data with a limit slightly above our max to check size
        // This is more efficient than count() + get()
        $gpsData = (clone $baseQuery)->limit(self::MAX_GPS_DATA_POINTS + 1)->get();

        // If we got more than MAX_GPS_DATA_POINTS, we need to sample
        if ($gpsData->count() > self::MAX_GPS_DATA_POINTS) {
            // Get total count only if we exceeded the limit
            $totalCount = (clone $baseQuery)->count();

            // For very large datasets, use intelligent sampling
            $sampleRate = (int)ceil($totalCount / self::MAX_GPS_DATA_POINTS);

            Log::warning('GPS data exceeds memory limit, applying sampling', [
                'tractor_id' => $tractor->id,
                'date' => $date->toDateString(),
                'total_points' => $totalCount,
                'sample_rate' => $sampleRate,
                'max_points' => self::MAX_GPS_DATA_POINTS
            ]);

            // Use chunking to process data in batches for memory efficiency
            // This prevents loading all data into memory at once
            $sampled = [];
            $index = 0;
            $lastPoint = null;
            $lastPointIndex = null;

            // Process data in chunks to avoid loading everything into memory
            (clone $baseQuery)->chunk(1000, function ($chunk) use (&$sampled, &$index, $sampleRate, &$lastPoint, &$lastPointIndex) {
                foreach ($chunk as $point) {
                    // Always keep first point
                    if ($index === 0) {
                        $sampled[] = $point;
                    }
                    // Sample every Nth point
                    elseif ($index % $sampleRate === 0) {
                        $sampled[] = $point;
                    }
                    // Track last point to include it at the end
                    $lastPoint = $point;
                    $lastPointIndex = $index;
                    $index++;
                }
            });

            // Always include last point if it wasn't already included (not first point, not sampled)
            if ($lastPoint !== null && $lastPointIndex !== null && $lastPointIndex !== 0 && $lastPointIndex % $sampleRate !== 0) {
                $sampled[] = $lastPoint;
            }

            return collect($sampled);
        }

        return $gpsData;
    }

    /**
     * Smooth out GPS errors by correcting single-point anomalies.
     * Optimized single-pass algorithm with memory efficiency.
     *
     * Rules:
     * - If a movement point is surrounded by stoppage points (before and after), treat it as stoppage (set speed=0)
     * - If a stoppage point is surrounded by movement points (before and after), treat it as movement (set speed=average of neighbors)
     *
     * @param \Illuminate\Support\Collection $gpsData
     * @return \Illuminate\Support\Collection Collection with corrected points
     */
    private function smoothGpsErrors($gpsData): \Illuminate\Support\Collection
    {
        $count = $gpsData->count();
        if ($count < 3) {
            return $gpsData;
        }

        // Use lightweight arrays instead of nested arrays to reduce memory
        // Store only essential values: status and speed as integers
        $statuses = [];
        $speeds = [];
        $pointsArray = [];

        foreach ($gpsData as $idx => $point) {
            $statuses[$idx] = (int)$point->status;
            $speeds[$idx] = (float)$point->speed;
            $pointsArray[$idx] = $point; // Keep reference for modifications
        }

        $maxIterations = 3; // Reduced from 5 - usually converges faster
        $iteration = 0;

        // Optimized iteration with lightweight arrays
        do {
            $hasCorrections = false;

            for ($i = 1; $i < $count - 1; $i++) {
                $currentStatus = $statuses[$i];
                $currentSpeed = $speeds[$i];
                $prevSpeed = $speeds[$i - 1];
                $nextSpeed = $speeds[$i + 1];
                $prevStatus = $statuses[$i - 1];
                $nextStatus = $statuses[$i + 1];

                $currentIsMovement = ($currentStatus == 1 && $currentSpeed > 0);
                $previousIsStoppage = ($prevSpeed == 0);
                $nextIsStoppage = ($nextSpeed == 0);
                $previousIsMovement = ($prevStatus == 1 && $prevSpeed > 0);
                $nextIsMovement = ($nextStatus == 1 && $nextSpeed > 0);
                $currentIsStoppage = ($currentSpeed == 0);

                // Case 1: Movement point surrounded by stoppage points
                if ($currentIsMovement && $previousIsStoppage && $nextIsStoppage) {
                    $pointsArray[$i]->speed = 0;
                    $speeds[$i] = 0;
                    $hasCorrections = true;
                }
                // Case 2: Stoppage point surrounded by movement points
                elseif ($currentIsStoppage && $previousIsMovement && $nextIsMovement) {
                    $avgSpeed = ($prevSpeed + $nextSpeed) / 2;
                    $newSpeed = $avgSpeed > 0 ? $avgSpeed : 1;
                    $pointsArray[$i]->speed = $newSpeed;
                    $speeds[$i] = $newSpeed;
                    $hasCorrections = true;
                }
            }

            $iteration++;
        } while ($hasCorrections && $iteration < $maxIterations);

        // Free memory
        unset($statuses, $speeds, $pointsArray);

        return $gpsData;
    }

    /**
     * Get the last point from the previous date
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return object|null
     */
    private function getLastPointFromPreviousDate(Tractor $tractor, Carbon $date): ?object
    {
        return $tractor->gpsData()
            ->whereDate('date_time', '<', $date)
            ->orderBy('date_time', 'desc')
            ->first();
    }

    /**
     * Convert a single point to PointsResource format
     *
     * @param object $point
     * @return object
     */
    private function convertSinglePointToResource($point): object
    {
        // Strict rule: Stoppage = speed == 0
        $isStopped = ((float)$point->speed == 0);

        return (object) [
            'id' => $point->id,
            'coordinate' => $point->coordinate,
            'speed' => $point->speed,
            'status' => $point->status,
            'is_starting_point' => false,
            'is_ending_point' => false,
            'is_stopped' => $isStopped,
            'directions' => $point->directions,
            'stoppage_time' => 0,
            'date_time' => $point->date_time,
        ];
    }

    /**
     * Extract movement points and stoppage points from GPS data (optimized)
     * Also finds first movement point ID in the same pass
     * Algorithm:
     * - The first point of the day is always included (stoppage or movement)
     * - All movement points (status == 1 && speed > 0) are included
     * - For stoppage segments, only the first stoppage point after a movement point is included
     * - Stoppage segments less than 60 seconds are ignored (treated as movement, not included in path)
     * - Calculate stoppage_time for each point inline
     * - Consecutive stoppage points are excluded from the final path
     *
     * @param \Illuminate\Support\Collection $gpsData
     * @return array [pathPoints, firstMovementPointId]
     */
    private function extractMovementPointsOptimized($gpsData): array
    {
        $pathPoints = [];
        $count = $gpsData->count();
        $stoppageStartIndex = null;
        $stoppagePointPathIndex = null; // Track index in pathPoints array where stoppage point was added
        $inStoppageSegment = false;
        $lastPointType = null;
        $hasSeenMovement = false;
        $firstMovementPointId = null;
        $consecutiveMovementCount = 0;
        $firstConsecutiveMovementPoint = null;
        $minStoppageSeconds = 60; // Ignore stoppages less than 60 seconds

        // Pre-cache timestamps as integers for efficient duration calculation and memory efficiency
        $timestamps = [];
        foreach ($gpsData as $idx => $point) {
            $ts = $point->date_time;
            $timestamps[$idx] = is_string($ts) ? \Carbon\Carbon::parse($ts)->timestamp : $ts->timestamp;
        }

        foreach ($gpsData as $index => $point) {
            // Pre-cast types once per point
            $status = (int)$point->status;
            $speed = (float)$point->speed;
            $isMovement = ($status == 1 && $speed > 0);
            $isStoppage = ($speed == 0);
            $isFirstPoint = ($index === 0);

            // Track first movement point (3 consecutive movements)
            if ($firstMovementPointId === null) {
                if ($isMovement) {
                    if ($consecutiveMovementCount === 0) {
                        $firstConsecutiveMovementPoint = $point;
                    }
                    $consecutiveMovementCount++;
                    if ($consecutiveMovementCount >= 3) {
                        $firstMovementPointId = $firstConsecutiveMovementPoint->id;
                    }
                } else {
                    $consecutiveMovementCount = 0;
                    $firstConsecutiveMovementPoint = null;
                }
            }

            if ($isMovement) {
                $hasSeenMovement = true;

                // If we were in a stoppage segment, check duration and decide whether to keep or remove
                if ($inStoppageSegment && $stoppageStartIndex !== null) {
                    // Calculate duration efficiently using pre-cached timestamps
                    $duration = 0;
                    for ($i = $stoppageStartIndex + 1; $i < $index; $i++) {
                        $duration += $timestamps[$i] - $timestamps[$i - 1];
                    }

                    // If stoppage duration < 60 seconds, remove the stoppage point from path (treat as movement)
                    if ($duration < $minStoppageSeconds) {
                        // Remove the stoppage point that was added to pathPoints
                        if ($stoppagePointPathIndex !== null && isset($pathPoints[$stoppagePointPathIndex])) {
                            array_splice($pathPoints, $stoppagePointPathIndex, 1);
                        }
                        // Continue as movement - no stoppage point in path
                    } else {
                        // Stoppage >= 60 seconds, keep it and set stoppage_time
                        if ($stoppagePointPathIndex !== null && isset($pathPoints[$stoppagePointPathIndex])) {
                            $pathPoints[$stoppagePointPathIndex]->stoppage_time = $duration;
                        }
                    }

                    $stoppageStartIndex = null;
                    $stoppagePointPathIndex = null;
                    $inStoppageSegment = false;
                }

                $point->stoppage_time = 0;
                $pathPoints[] = $point;
                $lastPointType = 'movement';

            } elseif ($isStoppage) {
                if ($isFirstPoint) {
                    // First point is always included even if stoppage
                    $point->stoppage_time = 0;
                    $pathPoints[] = $point;
                    $stoppageStartIndex = $index;
                    $stoppagePointPathIndex = count($pathPoints) - 1;
                    $inStoppageSegment = true;
                    $lastPointType = 'stoppage';
                } elseif ($hasSeenMovement && $lastPointType !== 'stoppage') {
                    // First stoppage point after movement - add it but may remove later if < 60 seconds
                    $point->stoppage_time = 0;
                    $pathPoints[] = $point;
                    $stoppageStartIndex = $index;
                    $stoppagePointPathIndex = count($pathPoints) - 1;
                    $inStoppageSegment = true;
                    $lastPointType = 'stoppage';
                } else {
                    // Consecutive stoppage point - track but don't add to path
                    $lastPointType = 'stoppage';
                }
            }
        }

        // Handle final stoppage segment
        if ($inStoppageSegment && $stoppageStartIndex !== null && $count > 0) {
            $duration = 0;
            for ($i = $stoppageStartIndex + 1; $i < $count; $i++) {
                $duration += $timestamps[$i] - $timestamps[$i - 1];
            }

            // First point is always included even if stoppage < 60 seconds
            $isFirstPointStoppage = ($stoppageStartIndex === 0);

            // If final stoppage duration < 60 seconds, remove it from path (unless it's the first point)
            if ($duration < $minStoppageSeconds && !$isFirstPointStoppage) {
                if ($stoppagePointPathIndex !== null && isset($pathPoints[$stoppagePointPathIndex])) {
                    array_splice($pathPoints, $stoppagePointPathIndex, 1);
                }
            } else {
                // Keep final stoppage and set stoppage_time
                if ($stoppagePointPathIndex !== null && isset($pathPoints[$stoppagePointPathIndex])) {
                    $pathPoints[$stoppagePointPathIndex]->stoppage_time = $duration;
                }
            }
        }

        // Free memory
        unset($timestamps);

        // Limit path points to prevent memory issues
        if (count($pathPoints) > self::MAX_POINTS_PER_PATH) {
            // Sample path points evenly to stay within limit
            $sampleRate = (int)ceil(count($pathPoints) / self::MAX_POINTS_PER_PATH);
            $sampledPoints = [];
            foreach ($pathPoints as $idx => $point) {
                if ($idx % $sampleRate === 0 || $idx === 0 || $idx === count($pathPoints) - 1) {
                    // Always include first and last points, sample others
                    $sampledPoints[] = $point;
                }
            }
            $pathPoints = $sampledPoints;
        }

        return [collect($pathPoints), $firstMovementPointId];
    }

    /**
     * Calculate stoppage duration between start and end indices
     * Based on the same logic as GpsDataAnalyzer
     * NOTE: This method is kept for backward compatibility but is no longer used in optimized path
     *
     * @param array $gpsDataArray
     * @param int $startIndex
     * @param int $endIndex
     * @return int Duration in seconds
     */
    private function calculateStoppageDuration(array $gpsDataArray, int $startIndex, int $endIndex): int
    {
        $duration = 0;

        for ($i = $startIndex + 1; $i <= $endIndex; $i++) {
            if (isset($gpsDataArray[$i]) && isset($gpsDataArray[$i - 1])) {
                // Handle both Carbon instances and string dates
                $currentTime = $gpsDataArray[$i]['date_time'];
                $previousTime = $gpsDataArray[$i - 1]['date_time'];

                // Convert to Carbon if it's a string
                if (is_string($currentTime)) {
                    $currentTime = \Carbon\Carbon::parse($currentTime);
                }
                if (is_string($previousTime)) {
                    $previousTime = \Carbon\Carbon::parse($previousTime);
                }

                $timeDiff = $currentTime->timestamp - $previousTime->timestamp;
                $duration += $timeDiff;
            }
        }

        return $duration;
    }

    /**
     * Convert movement and stoppage points to PointsResource format (optimized)
     * Uses pre-calculated firstMovementPointId to avoid additional iteration
     * Memory optimized to use arrays instead of objects where possible
     *
     * @param \Illuminate\Support\Collection $pathPoints
     * @param int|null $firstMovementPointId Pre-calculated first movement point ID
     * @return \Illuminate\Support\Collection
     */
    private function convertToPathPointsOptimized($pathPoints, ?int $firstMovementPointId): \Illuminate\Support\Collection
    {
        $result = [];
        $pathPointsArray = $pathPoints->all();

        foreach ($pathPointsArray as $point) {
            // Pre-cast types once
            $speed = (float)$point->speed;
            $isStopped = ($speed == 0);
            $stoppageTime = $point->stoppage_time ?? 0;
            $isStartingPoint = ($firstMovementPointId !== null && $point->id == $firstMovementPointId);

            // Create optimized object (using stdClass for compatibility)
            $result[] = (object) [
                'id' => $point->id,
                'coordinate' => $point->coordinate,
                'speed' => $point->speed,
                'status' => $point->status,
                'is_starting_point' => $isStartingPoint,
                'is_ending_point' => false,
                'is_stopped' => $isStopped,
                'directions' => $point->directions,
                'stoppage_time' => $stoppageTime,
                'date_time' => $point->date_time,
            ];
        }

        // Free memory
        unset($pathPointsArray);

        return collect($result);
    }

    /**
     * Find the ID of the first point in the first 3 consecutive movement points from original GPS data
     * NOTE: This method is kept for backward compatibility but is no longer used in optimized path
     * The first movement point ID is now calculated during extractMovementPointsOptimized
     *
     * @param \Illuminate\Support\Collection $gpsData Original GPS data (all points)
     * @return int|null ID of the first movement point, or null if not found
     */
    private function findFirstMovementPointIdFromOriginalData($gpsData): ?int
    {
        $consecutiveMovementCount = 0;
        $firstMovementPoint = null;

        foreach ($gpsData as $point) {
            // Strict rule: Movement = status == 1 AND speed > 0
            $isMovement = ((int)$point->status == 1 && (float)$point->speed > 0);

            if ($isMovement) {
                if ($consecutiveMovementCount === 0) {
                    // Start tracking a potential sequence - save the first point
                    $firstMovementPoint = $point;
                    $consecutiveMovementCount = 1;
                } else {
                    // Continue the sequence
                    $consecutiveMovementCount++;
                }

                // Found 3 consecutive movement points - return the ID of the first point
                if ($consecutiveMovementCount >= 3) {
                    return $firstMovementPoint->id;
                }
            } else {
                // Non-moving point breaks the sequence - reset
                $consecutiveMovementCount = 0;
                $firstMovementPoint = null;
            }
        }

        // Less than 3 consecutive movement points found
        return null;
    }


    /**
     * Get the maximum points per path for performance optimization.
     *
     * @return int
     */
    public function getMaxPointsPerPath(): int
    {
        return self::MAX_POINTS_PER_PATH;
    }

    /**
     * Get optimized tractor path with movement analysis
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function getOptimizedTractorPath(Tractor $tractor, Carbon $date)
    {
        return $this->getTractorPath($tractor, $date);
    }
}
