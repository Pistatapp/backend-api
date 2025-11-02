<?php

namespace App\Services;

use App\Models\Tractor;
use App\Http\Resources\PointsResource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TractorPathService
{
    private const MAX_POINTS_PER_PATH = 5000;

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

            // Stream GPS data with minimal memory
            $cursor = $tractor->gpsData()
                ->select(['gps_data.id as id', 'gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.directions', 'gps_data.date_time'])
                ->whereDate('gps_data.date_time', $date)
                ->orderBy('gps_data.date_time')
                ->cursor();

            // Single-pass smoothing via 3-point sliding window
            $smoothedStream = $this->smoothGpsErrorsStream($cursor);

            // Build final path in one pass (also computes stoppage durations and starting point)
            $pathPoints = $this->buildPathFromSmoothedStream($smoothedStream);

            // Enforce maximum number of points to control memory and payload size
            if (count($pathPoints) > self::MAX_POINTS_PER_PATH) {
                $pathPoints = $this->downsamplePath($pathPoints, self::MAX_POINTS_PER_PATH);
            }

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
     * Stream-based smoothing: correct single-point anomalies using a 3-point window without loading all rows.
     *
     * @param \Traversable $points
     * @return \Generator
     */
    private function smoothGpsErrorsStream($points): \Generator
    {
        $buffer = [];
        foreach ($points as $p) {
            $buffer[] = $p;
            if (count($buffer) < 3) {
                continue;
            }

            [$prev, $curr, $next] = $buffer;

            $prevSpeed = (float)$prev->speed;
            $nextSpeed = (float)$next->speed;
            $currSpeed = (float)$curr->speed;

            $prevIsMovement = ((int)$prev->status === 1 && $prevSpeed > 0);
            $nextIsMovement = ((int)$next->status === 1 && $nextSpeed > 0);
            $prevIsStoppage = ($prevSpeed == 0);
            $nextIsStoppage = ($nextSpeed == 0);
            $currIsMovement = ((int)$curr->status === 1 && $currSpeed > 0);
            $currIsStoppage = ($currSpeed == 0);

            if ($currIsMovement && $prevIsStoppage && $nextIsStoppage) {
                $curr->speed = 0;
            } elseif ($currIsStoppage && $prevIsMovement && $nextIsMovement) {
                $avgSpeed = ($prevSpeed + $nextSpeed) / 2;
                $curr->speed = $avgSpeed > 0 ? $avgSpeed : 1;
            }

            yield $curr;

            array_shift($buffer);
        }

        // Flush remaining points in buffer as-is
        if (count($buffer) === 2) {
            yield $buffer[0];
            yield $buffer[1];
        } elseif (count($buffer) === 1) {
            yield $buffer[0];
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
                if ($consecutiveMovementCount >= 3 && $firstMovementCandidateObj && $firstMovementCandidateObj->is_starting_point === false) {
                    $firstMovementCandidateObj->is_starting_point = true;
                    // Do not reset candidate; future sequences are irrelevant
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

    /**
     * Downsample the path to a maximum number of points while preserving important markers.
     * Keeps first/last and all stoppage markers, then uniformly samples the rest.
     *
     * @param array $pathPoints
     * @param int $max
     * @return array
     */
    private function downsamplePath(array $pathPoints, int $max): array
    {
        $total = count($pathPoints);
        if ($total <= $max) {
            return $pathPoints;
        }

        $indicesToKeep = [0, $total - 1];

        // Always keep stoppage markers and explicit start/end flags
        foreach ($pathPoints as $idx => $p) {
            if (!empty($p->is_stopped) || !empty($p->is_starting_point) || !empty($p->is_ending_point)) {
                $indicesToKeep[] = $idx;
            }
        }

        $indicesToKeep = array_values(array_unique($indicesToKeep));
        sort($indicesToKeep);

        $remaining = $max - count($indicesToKeep);
        if ($remaining <= 0) {
            // Trim to first $max preserved indices
            $indicesToKeep = array_slice($indicesToKeep, 0, $max);
        } else {
            // Uniformly sample additional indices
            $step = max(1, (int) floor($total / ($remaining + 1))); // avoid division by zero
            for ($i = $step; $i < $total - 1 && $remaining > 0; $i += $step) {
                if (!in_array($i, $indicesToKeep, true)) {
                    $indicesToKeep[] = $i;
                    $remaining--;
                }
            }
            sort($indicesToKeep);
        }

        $result = [];
        foreach ($indicesToKeep as $i) {
            $result[] = $pathPoints[$i];
        }
        return $result;
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
}
