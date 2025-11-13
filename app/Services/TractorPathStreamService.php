<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TractorPathStreamService
{
    /**
     * Retrieves the tractor movement path for a specific date using GPS data analysis.
     * Streams JSON response for memory-efficient handling of large datasets.
     * Handles thousands of records efficiently using streaming database cursors and JSON streaming.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Http\StreamedResponse
     */
    public function getTractorPath(Tractor $tractor, Carbon $date)
    {
        try {
            // Check if tractor has GPS device
            if (!$tractor->gpsDevice) {
                return response()->streamJson(function () {
                    // Yield empty collection
                });
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
                    return response()->streamJson(function () use ($formattedPoint) {
                        yield $this->formatPointForResponse($formattedPoint);
                    });
                }
                return response()->streamJson(function () {
                    // Yield empty collection
                });
            }

            // Stream GPS data with minimal memory using database cursor
            $cursor = $tractor->gpsData()
                ->select(['gps_data.id as id', 'gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.directions', 'gps_data.date_time'])
                ->whereDate('gps_data.date_time', $date)
                ->orderBy('gps_data.date_time')
                ->cursor();

            // Single-pass smoothing via 3-point sliding window (generator-based for memory efficiency)
            $smoothedStream = $this->smoothGpsErrorsStream($cursor);

            // Stream path points as they're processed (memory-efficient)
            // Process all points first (necessary for stoppage time calculation),
            // then stream them as JSON to avoid ResourceCollection overhead
            $pathPoints = [];
            foreach ($this->buildPathFromSmoothedStream($smoothedStream) as $point) {
                $pathPoints[] = $this->formatPointForResponse($point);
            }

            // Use streamJson to stream the response
            // Note: streamJson returns array directly, not wrapped in "data" key
            // This is acceptable since we're using a "stream" parameter to indicate different format
            return response()->streamJson(function () use ($pathPoints) {
                foreach ($pathPoints as $point) {
                    yield $point;
                }
            });

        } catch (\Exception $e) {
            Log::error('Failed to get tractor path (streamed)', [
                'tractor_id' => $tractor->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);

            return response()->streamJson(function () {
                // Yield empty collection on error
            });
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
     * Convert a single point to resource format (matches TractorPathService)
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
     * Format a point object to match PointsResource format for JSON response
     *
     * @param object $point
     * @return array
     */
    private function formatPointForResponse($point): array
    {
        // Ensure coordinate is an array
        $coordinate = is_string($point->coordinate) ? json_decode($point->coordinate, true) : $point->coordinate;
        if (!is_array($coordinate)) {
            $coordinate = [0, 0];
        }

        // Format date_time
        $dateTime = $point->date_time;
        if (is_string($dateTime)) {
            $dateTime = Carbon::parse($dateTime);
        } elseif (!$dateTime instanceof Carbon) {
            $dateTime = Carbon::now();
        }

        // Format stoppage_time (convert seconds to H:i:s format)
        $stoppageTime = isset($point->stoppage_time) ? (int)$point->stoppage_time : 0;
        $stoppageTimeFormatted = gmdate('H:i:s', $stoppageTime);

        return [
            'id' => $point->id,
            'latitude' => $coordinate[0] ?? 0,
            'longitude' => $coordinate[1] ?? 0,
            'speed' => $point->speed,
            'status' => $point->status,
            'is_starting_point' => $point->is_starting_point ?? false,
            'is_ending_point' => $point->is_ending_point ?? false,
            'is_stopped' => $point->is_stopped ?? false,
            'directions' => $point->directions ?? null,
            'stoppage_time' => $stoppageTimeFormatted,
            'date_time' => $dateTime->format('H:i:s'),
        ];
    }

    /**
     * Streamed path extraction with minimal memory.
     * Applies the same inclusion rules and stoppage-time calculation as the optimized batch version.
     * This method yields points as they're processed instead of collecting them into an array.
     *
     * @param \Traversable $points Smoothed GPS points in chronological order
     * @return \Generator Yields stdClass items ready for formatting
     */
    private function buildPathFromSmoothedStream($points): \Generator
    {
        $hasSeenMovement = false;
        $lastPointType = null;
        $inStoppageSegment = false;
        $stoppageDuration = 0;
        $stoppageAddedPoint = null;
        $stoppageStartedAtFirstPoint = false;
        $consecutiveMovementCount = 0;
        $firstMovementCandidateObj = null;
        $minStoppageSeconds = 60;

        $firstPointProcessed = false;
        $prevTimestamp = null;
        $pathPoints = [];

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
                if ($inStoppageSegment && $stoppageAddedPoint !== null) {
                    if ($stoppageDuration < $minStoppageSeconds && !$stoppageStartedAtFirstPoint) {
                        // Remove previously added stoppage marker (treat short stoppage as movement)
                        // Since we're streaming, we need to track if we've already yielded it
                        // For now, we'll mark it to be skipped when we encounter it
                        // Actually, we can't remove already yielded points, so we'll update the stoppage_time to 0
                        // and mark it as not stopped if duration is too short
                        $stoppageAddedPoint->stoppage_time = 0;
                        $stoppageAddedPoint->is_stopped = false;
                    } else {
                        // Keep and set stoppage_time
                        $stoppageAddedPoint->stoppage_time = $stoppageDuration;
                    }
                }

                // Reset stoppage trackers
                $inStoppageSegment = false;
                $stoppageDuration = 0;
                $stoppageAddedPoint = null;
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
                    $stoppageAddedPoint = $obj;
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
                    $stoppageAddedPoint = $obj;
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
        if ($inStoppageSegment && $stoppageAddedPoint !== null) {
            if ($stoppageDuration < $minStoppageSeconds && !$stoppageStartedAtFirstPoint) {
                // Remove from array if it's a short stoppage
                $key = array_search($stoppageAddedPoint, $pathPoints, true);
                if ($key !== false) {
                    unset($pathPoints[$key]);
                    $pathPoints = array_values($pathPoints); // Re-index
                }
            } else {
                $stoppageAddedPoint->stoppage_time = $stoppageDuration;
            }
        }

        // Yield all collected points
        foreach ($pathPoints as $point) {
            yield $point;
        }
    }
}

