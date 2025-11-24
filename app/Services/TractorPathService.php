<?php

namespace App\Services;

use App\Models\Tractor;
use App\Http\Resources\PointsResource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TractorPathService
{
    public function __construct(
        private GpsPathCorrectionService $pathCorrectionService,
    ) {
    }

    /**
     * Retrieves the tractor movement path for a specific date using GPS data analysis.
     * Optimized for performance without caching for real-time updates.
     * Handles thousands of records efficiently using streaming database cursors.
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

            // Efficient existence check without loading all rows
            $hasData = $tractor->gpsData()
                ->whereDate('gps_data.date_time', $date)
                ->limit(1)
                ->exists();

            if (!$hasData) {
                $lastPointFromPreviousDate = $this->getLastPointFromPreviousDate($tractor, $date);
                if ($lastPointFromPreviousDate) {
                    $formattedPoint = $this->convertSinglePointToResource($lastPointFromPreviousDate);
                    return PointsResource::collection(collect([$formattedPoint]));
                }
                return PointsResource::collection(collect());
            }

            // Stream GPS data with minimal memory using database cursor
            $cursor = $tractor->gpsData()
                ->select(['gps_data.id as id', 'gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.directions', 'gps_data.date_time'])
                ->whereDate('gps_data.date_time', $date)
                ->orderBy('gps_data.date_time')
                ->cursor();

            // Single-pass smoothing via 3-point sliding window (generator-based for memory efficiency)
            $smoothedStream = $this->pathCorrectionService->smoothSpeedStatusStream($cursor);

            // Build final path in one pass (also computes stoppage durations and starting point)
            // Memory-efficient: only stores filtered points (movement + stoppage markers), not all GPS points
            $pathPoints = $this->buildPathFromSmoothedStream($smoothedStream);

            return PointsResource::collection(collect($pathPoints));

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
     * Get the last point from the previous date
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return object|null
     */
    private function getLastPointFromPreviousDate(Tractor $tractor, Carbon $date): ?object
    {
        return $tractor->gpsData()
            ->whereDate('gps_data.date_time', '<', $date)
            ->orderBy('gps_data.date_time', 'desc')
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
     * Streamed path extraction with minimal memory.
     * Applies the same inclusion rules and stoppage-time calculation as the optimized batch version.
     *
     * @param \Traversable $points Smoothed GPS points in chronological order
     * @return array Array of stdClass items ready for PointsResource
     */
    private function buildPathFromSmoothedStream($points): array
    {
        $pathPoints = [];
        $hasSeenMovement = false;
        $lastPointType = null;
        $inStoppageSegment = false;
        $stoppageDuration = 0;
        $stoppageAddedIndex = null;
        $stoppageStartedAtFirstPoint = false;
        $consecutiveMovementCount = 0;
        $firstMovementCandidateObj = null;
        $startingPointAssigned = false;
        $minStoppageSeconds = 60;

        $firstPointProcessed = false;
        $prevTimestamp = null;

        foreach ($points as $point) {
            $status = (int)$point->status;
            $speed = (float)$point->speed;
            $isMovement = ($status === 1 && $speed > 0);
            $isStoppage = ($speed == 0);
            $isFirstPoint = !$firstPointProcessed;

            // Timestamp normalization
            $ts = $point->date_time;
            $timestamp = is_string($ts) ? \Carbon\Carbon::parse($ts)->timestamp : $ts->timestamp;

            // Accumulate stoppage duration while inside a stoppage segment
            if ($inStoppageSegment && $prevTimestamp !== null && $isStoppage) {
                $stoppageDuration += max(0, $timestamp - $prevTimestamp);
            }

            if ($isMovement) {
                $hasSeenMovement = true;

                // If we are closing a stoppage segment, decide to keep or drop the recorded stoppage point
                if ($inStoppageSegment && $stoppageAddedIndex !== null) {
                    if ($stoppageDuration < $minStoppageSeconds && !$stoppageStartedAtFirstPoint) {
                        // Remove previously added stoppage marker (treat short stoppage as movement)
                        array_splice($pathPoints, $stoppageAddedIndex, 1);
                    } else {
                        // Keep and set stoppage_time
                        if (isset($pathPoints[$stoppageAddedIndex])) {
                            $pathPoints[$stoppageAddedIndex]->stoppage_time = $stoppageDuration;
                        }
                    }
                }

                // Reset stoppage trackers
                $inStoppageSegment = false;
                $stoppageDuration = 0;
                $stoppageAddedIndex = null;
                $stoppageStartedAtFirstPoint = false;

                // Build movement path object
                $obj = (object) [
                    'id' => $point->id,
                    'coordinate' => $point->coordinate,
                    'speed' => $point->speed,
                    'status' => $point->status,
                    'is_starting_point' => false,
                    'is_ending_point' => false,
                    'is_stopped' => false,
                    'directions' => $point->directions,
                    'stoppage_time' => 0,
                    'date_time' => $point->date_time,
                ];

                $pathPoints[] = $obj;

                // Track first 3 consecutive movement points
                if ($consecutiveMovementCount === 0) {
                    $firstMovementCandidateObj = $obj;
                }
                $consecutiveMovementCount++;
                if (
                    !$startingPointAssigned &&
                    $consecutiveMovementCount >= 3 &&
                    $firstMovementCandidateObj &&
                    $firstMovementCandidateObj->is_starting_point === false
                ) {
                    $firstMovementCandidateObj->is_starting_point = true;
                    $startingPointAssigned = true;
                    // Do not reset candidate; future sequences are irrelevant once assigned
                }

                $lastPointType = 'movement';

            } elseif ($isStoppage) {
                // Any stoppage breaks movement streak
                $consecutiveMovementCount = 0;
                $firstMovementCandidateObj = null;

                if ($isFirstPoint) {
                    $obj = (object) [
                        'id' => $point->id,
                        'coordinate' => $point->coordinate,
                        'speed' => $point->speed,
                        'status' => $point->status,
                        'is_starting_point' => false,
                        'is_ending_point' => false,
                        'is_stopped' => true,
                        'directions' => $point->directions,
                        'stoppage_time' => 0,
                        'date_time' => $point->date_time,
                    ];
                    $pathPoints[] = $obj;
                    $stoppageAddedIndex = count($pathPoints) - 1;
                    $inStoppageSegment = true;
                    $stoppageDuration = 0;
                    $stoppageStartedAtFirstPoint = true;
                    $lastPointType = 'stoppage';
                } elseif ($hasSeenMovement && $lastPointType !== 'stoppage') {
                    $obj = (object) [
                        'id' => $point->id,
                        'coordinate' => $point->coordinate,
                        'speed' => $point->speed,
                        'status' => $point->status,
                        'is_starting_point' => false,
                        'is_ending_point' => false,
                        'is_stopped' => true,
                        'directions' => $point->directions,
                        'stoppage_time' => 0,
                        'date_time' => $point->date_time,
                    ];
                    $pathPoints[] = $obj;
                    $stoppageAddedIndex = count($pathPoints) - 1;
                    $inStoppageSegment = true;
                    $stoppageDuration = 0;
                    $stoppageStartedAtFirstPoint = false;
                    $lastPointType = 'stoppage';
                } else {
                    // Consecutive stoppage; do not add another point
                    $lastPointType = 'stoppage';
                }
            }

            $firstPointProcessed = true;
            $prevTimestamp = $timestamp;
        }

        // Finalize a trailing stoppage segment (end of day)
        if ($inStoppageSegment && $stoppageAddedIndex !== null) {
            if ($stoppageDuration < $minStoppageSeconds && !$stoppageStartedAtFirstPoint) {
                array_splice($pathPoints, $stoppageAddedIndex, 1);
            } else {
                if (isset($pathPoints[$stoppageAddedIndex])) {
                    $pathPoints[$stoppageAddedIndex]->stoppage_time = $stoppageDuration;
                }
            }
        }

        return $pathPoints;
    }

}
