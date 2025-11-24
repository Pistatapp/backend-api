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
    public function __construct(
        private GpsPathCorrectionService $pathCorrectionService,
    ) {
    }

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
                return response()->streamJson(new \EmptyIterator());
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
                    return response()->streamJson($this->generateFormattedPoints([$formattedPoint]));
                }
                return response()->streamJson(new \EmptyIterator());
            }

            // Stream GPS data with minimal memory using database cursor
            $cursor = $tractor->gpsData()
                ->select(['gps_data.id as id', 'gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.directions', 'gps_data.date_time'])
                ->whereDate('gps_data.date_time', $date)
                ->orderBy('gps_data.date_time')
                ->cursor();

            // Step 1: Correct obvious speed/status spikes with a light 3‑point window (same idea as TractorPathService)
            // $speedSmoothedStream = $this->pathCorrectionService->smoothSpeedStatusStream($cursor);

            // Step 2: Apply a streaming constant‑velocity Kalman‑style (alpha‑beta) filter on coordinates.
            // This reduces jitter and small jumps while remaining cheap enough for real‑time streaming.
            // $trajectorySmoothedStream = $this->pathCorrectionService->kalmanSmoothCoordinatesStream($cursor);

            // Stream path points directly as they're processed (true streaming for memory efficiency)
            // Format and yield points immediately without collecting in memory
            return response()->streamJson($this->generateFormattedPointsFromStream($cursor));

        } catch (\Exception $e) {
            Log::error('Failed to get tractor path (streamed)', [
                'tractor_id' => $tractor->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);

            return response()->streamJson(new \EmptyIterator());
        }
    }

    /**
     * Generate formatted points from a stream of path points
     *
     * @param \Traversable $smoothedStream
     * @return \Generator
     */
    private function generateFormattedPointsFromStream($smoothedStream): \Generator
    {
        foreach ($this->buildPathFromSmoothedStream($smoothedStream) as $point) {
            yield $this->formatPointForResponse($point);
        }
    }

    /**
     * Generate formatted points from an array of points
     *
     * @param array $points
     * @return \Generator
     */
    private function generateFormattedPoints(array $points): \Generator
    {
        foreach ($points as $point) {
            yield $this->formatPointForResponse($point);
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

        // Ensure date_time is available (use date_time from point, or fallback to timestamp if it exists)
        $dateTime = $point->date_time ?? ($point->timestamp ?? Carbon::now());

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
            'date_time' => $dateTime instanceof Carbon ? $dateTime : Carbon::parse($dateTime),
        ];
    }

    /**
     * Format a point object to match PointsResource format for JSON response
     * Optimized to avoid redundant parsing when date_time is already a Carbon instance
     *
     * @param object $point
     * @return array
     */
    private function formatPointForResponse($point): array
    {
        // Optimize: Parse coordinate once, cache result if needed
        $coordinate = $point->coordinate;
        if (is_string($coordinate)) {
            $coordinate = json_decode($coordinate, true) ?: [0, 0];
        }
        if (!is_array($coordinate)) {
            $coordinate = [0, 0];
        }

        // Optimize: Use pre-parsed Carbon instance if available, otherwise parse once
        // Handle cases where date_time might not exist (fallback to timestamp or now)
        $dateTime = $point->date_time ?? ($point->timestamp ?? null);
        if ($dateTime === null) {
            $dateTime = Carbon::now();
        } elseif (!$dateTime instanceof Carbon) {
            $dateTime = is_string($dateTime) ? Carbon::parse($dateTime) : Carbon::now();
        }

        // Format stoppage_time (convert seconds to H:i:s format)
        $stoppageTime = isset($point->stoppage_time) ? (int)$point->stoppage_time : 0;
        $stoppageTimeFormatted = $stoppageTime > 0 ? gmdate('H:i:s', $stoppageTime) : '00:00:00';

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
            'timestamp' => $dateTime->format('H:i:s'),
        ];
    }

    /**
     * Streamed path extraction with minimal memory.
     * Applies the same inclusion rules and stoppage-time calculation as the optimized batch version.
     * This method yields points as they're processed instead of collecting them into an array.
     * Uses deferred yielding for stoppage points to calculate duration accurately.
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
        $deferredStoppagePoint = null; // Only buffer one stoppage point at a time
        $stoppageStartedAtFirstPoint = false;
        $consecutiveMovementCount = 0;
        $movementBuffer = []; // Small buffer (max 2 points) for starting point detection
        $startingPointAssigned = false;
        $minStoppageSeconds = 60;

        $firstPointProcessed = false;
        $prevTimestamp = null;

        foreach ($points as $point) {
            // Optimize: cache type casts
            $status = (int)$point->status;
            $speed = (float)$point->speed;
            $isMovement = ($status === 1 && $speed > 0);
            $isStoppage = ($speed == 0);
            $isFirstPoint = !$firstPointProcessed;

            // Optimize: Parse timestamp once and reuse
            $ts = $point->date_time;
            if ($ts instanceof Carbon) {
                $timestamp = $ts->timestamp;
            } else {
                $timestamp = is_string($ts) ? Carbon::parse($ts)->timestamp : Carbon::now()->timestamp;
                // Store parsed Carbon instance for later use
                $point->date_time = Carbon::createFromTimestamp($timestamp);
            }

            // Accumulate stoppage duration while inside a stoppage segment
            if ($inStoppageSegment && $prevTimestamp !== null && $isStoppage) {
                $stoppageDuration += max(0, $timestamp - $prevTimestamp);
            }

            if ($isMovement) {
                $hasSeenMovement = true;

                // If we are closing a stoppage segment, decide to keep or drop the recorded stoppage point
                if ($inStoppageSegment && $deferredStoppagePoint !== null) {
                    if ($stoppageDuration < $minStoppageSeconds && !$stoppageStartedAtFirstPoint) {
                        // Skip short stoppage - don't yield it
                        $deferredStoppagePoint = null;
                    } else {
                        // Keep and set stoppage_time, then yield it
                        $deferredStoppagePoint->stoppage_time = $stoppageDuration;
                        yield $deferredStoppagePoint;
                        $deferredStoppagePoint = null;
                    }
                }

                // Reset stoppage trackers
                $inStoppageSegment = false;
                $stoppageDuration = 0;
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

                // Use small buffer for starting point detection (max 2 points)
                // When we have 3 consecutive movement points, mark the first as starting point
                $movementBuffer[] = $obj;
                if (count($movementBuffer) === 3) {
                    if (!$startingPointAssigned) {
                        $movementBuffer[0]->is_starting_point = true;
                        $startingPointAssigned = true;
                    }
                    // Yield the first buffered point now that we know it's a starting point
                    yield array_shift($movementBuffer);
                } elseif (count($movementBuffer) > 3) {
                    // Should not happen, but yield if buffer grows
                    yield array_shift($movementBuffer);
                }

                $consecutiveMovementCount++;
                $lastPointType = 'movement';

            } elseif ($isStoppage) {
                // Any stoppage breaks movement streak - flush movement buffer
                foreach ($movementBuffer as $bufferedPoint) {
                    yield $bufferedPoint;
                }
                $movementBuffer = [];
                $consecutiveMovementCount = 0;

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
                    $deferredStoppagePoint = $obj;
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
                    $deferredStoppagePoint = $obj;
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

        // Flush any remaining movement points in buffer
        foreach ($movementBuffer as $bufferedPoint) {
            yield $bufferedPoint;
        }

        // Finalize a trailing stoppage segment (end of day)
        if ($inStoppageSegment && $deferredStoppagePoint !== null) {
            if ($stoppageDuration < $minStoppageSeconds && !$stoppageStartedAtFirstPoint) {
                // Skip short trailing stoppage
            } else {
                $deferredStoppagePoint->stoppage_time = $stoppageDuration;
                yield $deferredStoppagePoint;
            }
        }
    }
}

