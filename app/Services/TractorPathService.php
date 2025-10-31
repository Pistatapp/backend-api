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

            // Analyze GPS data to get movement details
            $analysisResults = $this->gpsDataAnalyzer
                ->loadFromRecords($gpsData)
                ->analyze();

            // Extract movement and strategic stoppage points from analysis results
            $pathPoints = $this->extractMovementPoints($gpsData, $analysisResults);

            // Convert to PointsResource format
            $formattedPathPoints = $this->convertToPathPoints($pathPoints);

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
     * Extract movement points and strategic stoppage points from GPS data
     * Rules:
     * - Every movement point should be present in the path
     * - Only the first stoppage point after the last movement point should be present
     * - Consecutive stoppage points should be excluded
     * - Calculate stoppage duration for each stoppage point
     *
     * @param \Illuminate\Support\Collection $gpsData
     * @param array $analysisResults
     * @return \Illuminate\Support\Collection
     */
    private function extractMovementPoints($gpsData, array $analysisResults): \Illuminate\Support\Collection
    {
        $pathPoints = collect();
        $lastPointWasMovement = false;
        $lastMovementIndex = -1;
        $stoppageStartIndex = null;
        $gpsDataArray = $gpsData->toArray();

        foreach ($gpsData as $index => $point) {
            $isMovement = $point->status == 1 && $point->speed > 0;
            $isStoppage = $point->status == 0 && $point->speed == 0;

            if ($isMovement) {
                // Calculate stoppage duration if we were in a stoppage period
                if ($stoppageStartIndex !== null) {
                    $stoppageDuration = $this->calculateStoppageDuration($gpsDataArray, $stoppageStartIndex, $index - 1);
                    // Update the last stoppage point with duration
                    if ($pathPoints->isNotEmpty()) {
                        $lastPoint = $pathPoints->last();
                        if ($lastPoint->status == 0 && $lastPoint->speed == 0) {
                            $lastPoint->stoppage_duration = $stoppageDuration;
                        }
                    }
                    $stoppageStartIndex = null;
                }

                // Always include movement points
                $pathPoints->push($point);
                $lastPointWasMovement = true;
                $lastMovementIndex = $index;
            } elseif ($isStoppage && $lastPointWasMovement && $index === $lastMovementIndex + 1) {
                // Include only the first stoppage point immediately after a movement
                $pathPoints->push($point);
                $lastPointWasMovement = false;
                $stoppageStartIndex = $index;
            } else {
                // Skip consecutive stoppage points but continue tracking stoppage duration
                $lastPointWasMovement = false;
                if ($stoppageStartIndex !== null) {
                    // Continue stoppage duration calculation
                } else {
                    $stoppageStartIndex = $index;
                }
            }
        }

        // Handle final stoppage if we end with a stoppage
        if ($stoppageStartIndex !== null) {
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
     * @return \Illuminate\Support\Collection
     */
    private function convertToPathPoints($pathPoints): \Illuminate\Support\Collection
    {
        return $pathPoints->map(function ($point) {
            $isStopped = $point->status == 0 && $point->speed == 0;
            $stoppageDuration = $isStopped ? ($point->stoppage_duration ?? 0) : 0;

            // Create a mock object that matches PointsResource expectations
            return (object) [
                'id' => $point->id,
                'coordinate' => $point->coordinate,
                'speed' => $point->speed,
                'status' => $point->status,
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => $isStopped,
                'directions' => null,
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
