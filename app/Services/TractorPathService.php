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
     * - If a movement point is surrounded by stoppage points (before and after), treat it as stoppage (set status=0, speed=0)
     * - If a stoppage point is surrounded by movement points (before and after), treat it as movement (set status=1, speed=average of neighbors)
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

        // Convert to array for easier manipulation
        $dataArray = $gpsData->values()->all();
        $maxIterations = 5; // Prevent infinite loops
        $iteration = 0;

        // Iterate until no more corrections are needed
        do {
            $hasCorrections = false;

            for ($index = 1; $index < count($dataArray) - 1; $index++) {
                $point = $dataArray[$index];
                $previousPoint = $dataArray[$index - 1];
                $nextPoint = $dataArray[$index + 1];

                // Get current values from points (which may have been corrected in previous iterations)
                // Ensure proper type conversion (speed and status might be strings in DB)
                $currentStatus = (int)$point->status;
                $currentSpeed = (float)$point->speed;
                $prevStatus = (int)$previousPoint->status;
                $prevSpeed = (float)$previousPoint->speed;
                $nextStatus = (int)$nextPoint->status;
                $nextSpeed = (float)$nextPoint->speed;

                // Strict rules:
                // Stoppage = speed == 0
                // Movement = status == 1 AND speed > 0
                $currentIsMovement = ($currentStatus == 1 && $currentSpeed > 0);
                $currentIsStoppage = ($currentSpeed == 0);

                $previousIsMovement = ($prevStatus == 1 && $prevSpeed > 0);
                $previousIsStoppage = ($prevSpeed == 0);

                $nextIsMovement = ($nextStatus == 1 && $nextSpeed > 0);
                $nextIsStoppage = ($nextSpeed == 0);

                // Case 1: Movement point surrounded by stoppage points -> set speed to 0 (don't touch status)
                if ($currentIsMovement && $previousIsStoppage && $nextIsStoppage) {
                    $point->speed = 0;
                    $hasCorrections = true;
                }
                // Case 2: Stoppage point surrounded by movement points -> set speed to average of neighbors (don't touch status)
                elseif ($currentIsStoppage && $previousIsMovement && $nextIsMovement) {
                    $avgSpeed = ($prevSpeed + $nextSpeed) / 2;
                    $point->speed = $avgSpeed > 0 ? $avgSpeed : 1;
                    $hasCorrections = true;
                }
            }

            $iteration++;
        } while ($hasCorrections && $iteration < $maxIterations);

        // Return the corrected collection
        return collect($dataArray);
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
     * Extract movement points and stoppage points from GPS data
     * Algorithm:
     * - The first point of the day is always included (stoppage or movement)
     * - All movement points (status == 1 && speed > 0) are included
     * - For stoppage segments, only the first stoppage point after a movement point is included
     * - Calculate stoppage_time for each point:
     *   * For stoppage points: stoppage_time = cumulative duration from start of stoppage segment to end of segment (when movement begins)
     *   * For movement points: stoppage_time = 0
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
            // Strict rules:
            // Stoppage = speed == 0
            // Movement = status == 1 AND speed > 0
            $isMovement = ((int)$point->status == 1 && (float)$point->speed > 0);
            $isStoppage = ((float)$point->speed == 0);
            $isFirstPoint = $index === 0; // Check if this is the first point of the day

            if ($isMovement) {
                // Mark that we've seen movement
                $hasSeenMovement = true;

                // If we were in a stoppage segment, calculate and update the stoppage_time
                // stoppage_time = duration from start of stoppage segment to when movement begins (current index - 1)
                if ($inStoppageSegment && $stoppageStartIndex !== null) {
                    $stoppageDuration = $this->calculateStoppageDuration($gpsDataArray, $stoppageStartIndex, $index - 1);
                    // Update the last stoppage point with stoppage_time
                    if ($pathPoints->isNotEmpty()) {
                        $lastPoint = $pathPoints->last();
                        // Strict rule: Stoppage = speed == 0
                        if ((float)$lastPoint->speed == 0) {
                            $lastPoint->stoppage_time = $stoppageDuration;
                        }
                    }
                    $stoppageStartIndex = null;
                    $inStoppageSegment = false;
                }

                // Always include movement points
                // Movement points have stoppage_time = 0 (end of stoppage / start of movement)
                $point->stoppage_time = 0;
                $pathPoints->push($point);
                $lastPointType = 'movement';

            } elseif ($isStoppage) {
                // Always include the first point of the day, even if it's a stoppage
                if ($isFirstPoint) {
                    // Start of stoppage segment - stoppage_time will be calculated when movement begins
                    $point->stoppage_time = 0; // Will be updated when movement begins or segment ends
                    $pathPoints->push($point);
                    $stoppageStartIndex = $index;
                    $inStoppageSegment = true;
                    $lastPointType = 'stoppage';
                } elseif ($hasSeenMovement && $lastPointType !== 'stoppage') {
                    // This is the first stoppage point in a new stoppage segment (after movement)
                    // End of movement / start of stoppage - stoppage_time will be calculated when movement begins
                    $point->stoppage_time = 0; // Will be updated when movement begins or segment ends
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
            // Calculate stoppage_time from start of segment to the end of data
            $stoppageDuration = $this->calculateStoppageDuration($gpsDataArray, $stoppageStartIndex, count($gpsDataArray) - 1);
            if ($pathPoints->isNotEmpty()) {
                $lastPoint = $pathPoints->last();
                // Strict rule: Stoppage = speed == 0
                if ((float)$lastPoint->speed == 0) {
                    $lastPoint->stoppage_time = $stoppageDuration;
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
            // Strict rules:
            // Stoppage = speed == 0
            // Movement = status == 1 AND speed > 0
            $isStopped = ((float)$point->speed == 0);
            // stoppage_time is already calculated in extractMovementPoints
            $stoppageTime = $point->stoppage_time ?? 0;
            $isMovement = ((int)$point->status == 1 && (float)$point->speed > 0);

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
                'stoppage_time' => $stoppageTime, // Calculated stoppage_time from segment start to movement begin
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
