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

            if ($gpsData->isEmpty()) {
                return PointsResource::collection(collect());
            }

            // Get start work time for the tractor
            $startWorkTime = null;
            if ($tractor->start_work_time) {
                $startWorkTime = Carbon::parse($date->toDateString() . ' ' . $tractor->start_work_time);
            }

            // Extract movement and stoppage points according to the algorithm
            $pathPoints = $this->extractMovementPoints($gpsData);

            // Convert to PointsResource format
            $formattedPathPoints = $this->convertToPathPoints($pathPoints, $startWorkTime);

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
     * Extract movement points and stoppage points from GPS data
     * Algorithm:
     * - All movement points (status == 1 && speed > 0) are included
     * - For stoppage segments, only the first stoppage point is included
     * - Calculate stoppage duration for each stoppage segment and add to the first stoppage point
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

        foreach ($gpsData as $index => $point) {
            $isMovement = $point->status == 1 && $point->speed > 0;
            $isStoppage = $point->status == 0 && $point->speed == 0;

            if ($isMovement) {
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
                // Check if this is the start of a new stoppage segment
                if ($lastPointType !== 'stoppage') {
                    // This is the first stoppage point in a new stoppage segment
                    $pathPoints->push($point);
                    $stoppageStartIndex = $index;
                    $inStoppageSegment = true;
                } else {
                    // This is a consecutive stoppage point - skip it but track for duration calculation
                    if ($stoppageStartIndex === null) {
                        // Should not happen, but handle edge case
                        $stoppageStartIndex = $index;
                    }
                }
                $lastPointType = 'stoppage';
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
     *
     * @param \Illuminate\Support\Collection $pathPoints
     * @param Carbon|null $startWorkTime
     * @return \Illuminate\Support\Collection
     */
    private function convertToPathPoints($pathPoints, ?Carbon $startWorkTime): \Illuminate\Support\Collection
    {
        $startingPointMarked = false;

        return $pathPoints->map(function ($point) use ($startWorkTime, &$startingPointMarked) {
            $isStopped = $point->status == 0 && $point->speed == 0;
            $stoppageDuration = $isStopped ? ($point->stoppage_duration ?? 0) : 0;
            $isMovement = $point->status == 1 && $point->speed > 0;

            // Determine if this is the starting point
            // Mark the first movement point after start work time as starting point
            $isStartingPoint = false;
            if (!$startingPointMarked && $isMovement && $startWorkTime) {
                $pointTime = is_string($point->date_time)
                    ? Carbon::parse($point->date_time)
                    : $point->date_time;

                if ($pointTime->gte($startWorkTime)) {
                    $isStartingPoint = true;
                    $startingPointMarked = true;
                }
            }

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
