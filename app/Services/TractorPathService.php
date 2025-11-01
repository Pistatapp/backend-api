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

            // Smooth out GPS errors (single-point anomalies)
            $smoothedGpsData = $this->smoothGpsErrors($gpsData);

            // Extract movement and stoppage points according to the algorithm
            $pathPoints = $this->extractMovementPoints($smoothedGpsData);

            // Convert to PointsResource format (use smoothed data for consistency)
            $formattedPathPoints = $this->convertToPathPoints($pathPoints, $smoothedGpsData);

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
     *
     * Rules:
     * - If a movement point is surrounded by stoppage points (before and after), treat it as stoppage
     * - If a stoppage point is surrounded by movement points (before and after), treat it as movement
     *
     * @param \Illuminate\Support\Collection $gpsData
     * @return \Illuminate\Support\Collection Collection with corrected points
     */
    private function smoothGpsErrors($gpsData): \Illuminate\Support\Collection
    {
        if ($gpsData->count() < 3) {
            // Need at least 3 points to detect anomalies
            return $gpsData;
        }

        $smoothedData = collect();
        $dataArray = $gpsData->values()->all();

        foreach ($dataArray as $index => $point) {
            $isFirstPoint = $index === 0;
            $isLastPoint = $index === count($dataArray) - 1;

            // Skip smoothing for first and last points (no neighbors on both sides)
            if ($isFirstPoint || $isLastPoint) {
                $smoothedData->push($point);
                continue;
            }

            $currentIsMovement = $point->status == 1 && $point->speed > 0;
            $currentIsStoppage = $point->status == 0 && $point->speed == 0;

            $previousPoint = $dataArray[$index - 1];
            $nextPoint = $dataArray[$index + 1];

            $previousIsMovement = $previousPoint->status == 1 && $previousPoint->speed > 0;
            $previousIsStoppage = $previousPoint->status == 0 && $previousPoint->speed == 0;

            $nextIsMovement = $nextPoint->status == 1 && $nextPoint->speed > 0;
            $nextIsStoppage = $nextPoint->status == 0 && $nextPoint->speed == 0;

            // Case 1: Movement point surrounded by stoppage points -> treat as stoppage
            if ($currentIsMovement && $previousIsStoppage && $nextIsStoppage) {
                // Create a copy with corrected status
                $correctedPoint = clone $point;
                $correctedPoint->status = 0;
                $correctedPoint->speed = 0;
                $smoothedData->push($correctedPoint);
                continue;
            }

            // Case 2: Stoppage point surrounded by movement points -> treat as movement
            if ($currentIsStoppage && $previousIsMovement && $nextIsMovement) {
                // Create a copy with corrected status
                // Use average speed from neighbors or default to a small value
                $avgSpeed = ($previousPoint->speed + $nextPoint->speed) / 2;
                $correctedPoint = clone $point;
                $correctedPoint->status = 1;
                $correctedPoint->speed = $avgSpeed > 0 ? $avgSpeed : 1;
                $smoothedData->push($correctedPoint);
                continue;
            }

            // No correction needed
            $smoothedData->push($point);
        }

        return $smoothedData;
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
        $isStopped = $point->status == 0 && $point->speed == 0;

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
     * Extract movement points and stoppage points from GPS data
     * Algorithm:
     * - The first point of the day is always included (stoppage or movement)
     * - All movement points (status == 1 && speed > 0) are included
     * - For stoppage segments, only the first stoppage point after a movement point is included
     * - Calculate stoppage duration from first stoppage point to consecutive stoppage points
     * - Consecutive stoppage points are excluded from the final path
     *
     * @param \Illuminate\Support\Collection $gpsData
     * @return \Illuminate\Support\Collection
     */
    private function extractMovementPoints($gpsData): \Illuminate\Support\Collection
    {
        $pathPoints = collect();
        $gpsDataArray = $gpsData->toArray();
        $stoppageStartIndex = null;
        $inStoppageSegment = false;
        $lastPointType = null; // 'movement' or 'stoppage'
        $hasSeenMovement = false; // Track if we've seen at least one movement point

        foreach ($gpsData as $index => $point) {
            $isMovement = $point->status == 1 && $point->speed > 0;
            $isStoppage = $point->status == 0 && $point->speed == 0;
            $isFirstPoint = $index === 0; // Check if this is the first point of the day

            if ($isMovement) {
                // Mark that we've seen movement
                $hasSeenMovement = true;

                // If we were in a stoppage segment, calculate and update the stoppage duration
                if ($inStoppageSegment && $stoppageStartIndex !== null) {
                    $stoppageDuration = $this->calculateStoppageDuration($gpsDataArray, $stoppageStartIndex, $index - 1);
                    // Update the last stoppage point with duration
                    if ($pathPoints->isNotEmpty()) {
                        $lastPoint = $pathPoints->last();
                        if ($lastPoint->status == 0 && $lastPoint->speed == 0) {
                            $lastPoint->stoppage_duration = $stoppageDuration;
                        }
                    }
                    $stoppageStartIndex = null;
                    $inStoppageSegment = false;
                }

                // Always include movement points
                $pathPoints->push($point);
                $lastPointType = 'movement';

            } elseif ($isStoppage) {
                // Always include the first point of the day, even if it's a stoppage
                if ($isFirstPoint) {
                    $pathPoints->push($point);
                    $stoppageStartIndex = $index;
                    $inStoppageSegment = true;
                    $lastPointType = 'stoppage';
                } elseif ($hasSeenMovement && $lastPointType !== 'stoppage') {
                    // This is the first stoppage point in a new stoppage segment (after movement)
                    $pathPoints->push($point);
                    $stoppageStartIndex = $index;
                    $inStoppageSegment = true;
                    $lastPointType = 'stoppage';
                } elseif ($lastPointType === 'stoppage') {
                    // This is a consecutive stoppage point - skip it but track for duration calculation
                    if ($stoppageStartIndex === null && $inStoppageSegment) {
                        // Edge case: should not happen, but handle it
                        $stoppageStartIndex = $index;
                    }
                    // Update last point type but don't add to path
                    $lastPointType = 'stoppage';
                } else {
                    // This is a stoppage point before any movement (but not first point) - skip it
                    $lastPointType = 'stoppage';
                }
            }
        }

        // Handle final stoppage if we end with a stoppage segment
        if ($inStoppageSegment && $stoppageStartIndex !== null) {
            $stoppageDuration = $this->calculateStoppageDuration($gpsDataArray, $stoppageStartIndex, count($gpsDataArray) - 1);
            if ($pathPoints->isNotEmpty()) {
                $lastPoint = $pathPoints->last();
                if ($lastPoint->status == 0 && $lastPoint->speed == 0) {
                    $lastPoint->stoppage_duration = $stoppageDuration;
                }
            }
        }

        return $pathPoints;
    }

    /**
     * Calculate stoppage duration between start and end indices
     * Based on the same logic as GpsDataAnalyzer
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
     * Convert movement and stoppage points to PointsResource format
     * Detects the first 3 consecutive movement points and marks the first as starting point.
     * Uses the original GPS data to find the first movement point (same algorithm as GpsDataAnalyzer).
     *
     * @param \Illuminate\Support\Collection $pathPoints
     * @param \Illuminate\Support\Collection $gpsData Original GPS data to find first movement point
     * @return \Illuminate\Support\Collection
     */
    private function convertToPathPoints($pathPoints, $gpsData): \Illuminate\Support\Collection
    {
        // Find the first movement point ID from the original GPS data using the same algorithm as GpsDataAnalyzer
        $firstMovementPointId = $this->findFirstMovementPointIdFromOriginalData($gpsData);

        return $pathPoints->map(function ($point, $index) use ($firstMovementPointId) {
            $isStopped = $point->status == 0 && $point->speed == 0;
            $stoppageDuration = $isStopped ? ($point->stoppage_duration ?? 0) : 0;
            $isMovement = $point->status == 1 && $point->speed > 0;

            // Mark the point as starting point if it matches the first movement point from original data
            $isStartingPoint = $firstMovementPointId !== null && $point->id == $firstMovementPointId;

            // Create a mock object that matches PointsResource expectations
            return (object) [
                'id' => $point->id,
                'coordinate' => $point->coordinate,
                'speed' => $point->speed,
                'status' => $point->status,
                'is_starting_point' => $isStartingPoint,
                'is_ending_point' => false,
                'is_stopped' => $isStopped,
                'directions' => $point->directions,
                'stoppage_time' => $stoppageDuration, // Actual calculated stoppage duration
                'date_time' => $point->date_time,
            ];
        });
    }

    /**
     * Find the ID of the first point in the first 3 consecutive movement points from original GPS data
     * Uses the same algorithm as GpsDataAnalyzer to ensure consistency
     *
     * @param \Illuminate\Support\Collection $gpsData Original GPS data (all points)
     * @return int|null ID of the first movement point, or null if not found
     */
    private function findFirstMovementPointIdFromOriginalData($gpsData): ?int
    {
        $consecutiveMovementCount = 0;
        $firstMovementPoint = null;

        foreach ($gpsData as $point) {
            // Use the same movement detection logic as GpsDataAnalyzer: status == 1 && speed > 0
            $isMovement = $point->status == 1 && $point->speed > 0;

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
