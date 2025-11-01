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

            // Extract movement points and find first movement point ID in single pass
            [$pathPoints, $firstMovementPointId] = $this->extractMovementPointsOptimized($smoothedGpsData);

            // Convert to PointsResource format
            $formattedPathPoints = $this->convertToPathPointsOptimized($pathPoints, $firstMovementPointId);

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
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    private function getOptimizedGpsData(Tractor $tractor, Carbon $date): \Illuminate\Support\Collection
    {
        return $tractor->gpsData()
            ->whereDate('date_time', $date)
            ->orderBy('date_time')
            ->get();
    }

    /**
     * Smooth out GPS errors by correcting single-point anomalies.
     * Optimized single-pass algorithm.
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

        // Pre-cache type conversions for all points to avoid repeated casting
        $points = $gpsData->all();
        $cached = [];
        foreach ($points as $idx => $point) {
            $cached[$idx] = [
                'status' => (int)$point->status,
                'speed' => (float)$point->speed,
                'point' => $point,
            ];
        }

        $maxIterations = 3; // Reduced from 5 - usually converges faster
        $iteration = 0;

        // Optimized iteration with cached values
        do {
            $hasCorrections = false;

            for ($i = 1; $i < $count - 1; $i++) {
                $current = &$cached[$i];
                $prev = $cached[$i - 1];
                $next = $cached[$i + 1];

                $currentIsMovement = ($current['status'] == 1 && $current['speed'] > 0);
                $previousIsStoppage = ($prev['speed'] == 0);
                $nextIsStoppage = ($next['speed'] == 0);
                $previousIsMovement = ($prev['status'] == 1 && $prev['speed'] > 0);
                $nextIsMovement = ($next['status'] == 1 && $next['speed'] > 0);
                $currentIsStoppage = ($current['speed'] == 0);

                // Case 1: Movement point surrounded by stoppage points
                if ($currentIsMovement && $previousIsStoppage && $nextIsStoppage) {
                    $current['point']->speed = 0;
                    $current['speed'] = 0;
                    $hasCorrections = true;
                }
                // Case 2: Stoppage point surrounded by movement points
                elseif ($currentIsStoppage && $previousIsMovement && $nextIsMovement) {
                    $avgSpeed = ($prev['speed'] + $next['speed']) / 2;
                    $newSpeed = $avgSpeed > 0 ? $avgSpeed : 1;
                    $current['point']->speed = $newSpeed;
                    $current['speed'] = $newSpeed;
                    $hasCorrections = true;
                }
            }

            $iteration++;
        } while ($hasCorrections && $iteration < $maxIterations);

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
        $inStoppageSegment = false;
        $lastPointType = null;
        $hasSeenMovement = false;
        $firstMovementPointId = null;
        $consecutiveMovementCount = 0;
        $firstConsecutiveMovementPoint = null;

        // Pre-cache timestamps for efficient duration calculation
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

                // If we were in a stoppage segment, calculate stoppage_time inline
                if ($inStoppageSegment && $stoppageStartIndex !== null) {
                    // Calculate duration efficiently using pre-cached timestamps
                    $duration = 0;
                    for ($i = $stoppageStartIndex + 1; $i < $index; $i++) {
                        $duration += $timestamps[$i] - $timestamps[$i - 1];
                    }
                    // Update the last stoppage point
                    $lastPathIdx = count($pathPoints) - 1;
                    if ($lastPathIdx >= 0 && (float)$pathPoints[$lastPathIdx]->speed == 0) {
                        $pathPoints[$lastPathIdx]->stoppage_time = $duration;
                    }
                    $stoppageStartIndex = null;
                    $inStoppageSegment = false;
                }

                $point->stoppage_time = 0;
                $pathPoints[] = $point;
                $lastPointType = 'movement';

            } elseif ($isStoppage) {
                if ($isFirstPoint) {
                    $point->stoppage_time = 0;
                    $pathPoints[] = $point;
                    $stoppageStartIndex = $index;
                    $inStoppageSegment = true;
                    $lastPointType = 'stoppage';
                } elseif ($hasSeenMovement && $lastPointType !== 'stoppage') {
                    $point->stoppage_time = 0;
                    $pathPoints[] = $point;
                    $stoppageStartIndex = $index;
                    $inStoppageSegment = true;
                    $lastPointType = 'stoppage';
                } else {
                    $lastPointType = 'stoppage';
                }
            }
        }

        // Handle final stoppage
        if ($inStoppageSegment && $stoppageStartIndex !== null && $count > 0) {
            $duration = 0;
            for ($i = $stoppageStartIndex + 1; $i < $count; $i++) {
                $duration += $timestamps[$i] - $timestamps[$i - 1];
            }
            $lastPathIdx = count($pathPoints) - 1;
            if ($lastPathIdx >= 0 && (float)$pathPoints[$lastPathIdx]->speed == 0) {
                $pathPoints[$lastPathIdx]->stoppage_time = $duration;
            }
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
     *
     * @param \Illuminate\Support\Collection $pathPoints
     * @param int|null $firstMovementPointId Pre-calculated first movement point ID
     * @return \Illuminate\Support\Collection
     */
    private function convertToPathPointsOptimized($pathPoints, ?int $firstMovementPointId): \Illuminate\Support\Collection
    {
        return $pathPoints->map(function ($point) use ($firstMovementPointId) {
            // Pre-cast types once
            $speed = (float)$point->speed;
            $isStopped = ($speed == 0);
            $stoppageTime = $point->stoppage_time ?? 0;
            $isStartingPoint = ($firstMovementPointId !== null && $point->id == $firstMovementPointId);

            // Create optimized object
            return (object) [
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
        });
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
