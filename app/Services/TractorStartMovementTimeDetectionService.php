<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TractorStartMovementTimeDetectionService
{
    public function __construct() {}

    /**
     * Detect start movement time for a tractor using GPS data analysis.
     * Uses the same algorithm as TractorPathService: finds the first point in the first 3 consecutive movement points.
     * Movement = status == 1 AND speed > 0
     * Results are cached per tractor and date for performance optimization.
     *
     * @param Tractor $tractor
     * @param Carbon|null $date Optional date to analyze (defaults to today)
     * @return string|null Start movement time in H:i:s format, or null if not found
     */
    public function detectStartMovementTime(Tractor $tractor, ?Carbon $date = null): ?string
    {
        // Early exit: Check if tractor has GPS device
        if (!$tractor->gpsDevice) {
            return null;
        }

        // Use provided date or default to today
        $targetDate = $date ?? Carbon::today();

        // Generate cache key unique per tractor and date
        $cacheKey = $this->getCacheKey($tractor->id, $targetDate);

        // Try to get cached result first for performance optimization
        // Use has() to distinguish between "not cached" and "cached as null"
        if (cache()->has($cacheKey)) {
            return cache()->get($cacheKey);
        }

        try {
            // Get GPS data with optimized query
            $gpsData = $this->getOptimizedGpsData($tractor, $targetDate);

            if ($gpsData->isEmpty()) {
                // Cache null result but with shorter TTL (in case GPS data arrives later)
                // Cache until end of day, or minimum 1 hour
                $cacheUntil = $targetDate->copy()->endOfDay();
                $cacheTtl = max($cacheUntil->diffInSeconds(Carbon::now()), 3600);
                cache()->put($cacheKey, null, $cacheTtl);
                return null;
            }

            // Find first movement point using the same algorithm as TractorPathService
            $firstMovementPoint = $this->findFirstMovementPoint($gpsData);

            $result = null;
            if ($firstMovementPoint) {
                $pointTime = is_string($firstMovementPoint->date_time)
                    ? Carbon::parse($firstMovementPoint->date_time)
                    : $firstMovementPoint->date_time;
                $result = $pointTime->format('H:i:s');
            }

            // Cache the result until end of day (start movement time won't change once detected)
            $cacheUntil = $targetDate->copy()->endOfDay();
            $cacheTtl = $cacheUntil->diffInSeconds(Carbon::now());

            // Only cache if TTL is positive (not in the past)
            if ($cacheTtl > 0) {
                cache()->put($cacheKey, $result, $cacheTtl);
            }

            return $result;
        } catch (\Exception $e) {
            // Log error and return null if analysis fails
            Log::error('Failed to detect start movement time for tractor ' . $tractor->id . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Detect start movement time for multiple tractors (optimized batch processing)
     *
     * @param \Illuminate\Support\Collection $tractors
     * @param Carbon|null $date Optional date to analyze (defaults to today)
     * @return \Illuminate\Support\Collection
     */
    public function detectStartMovementTimeForTractors($tractors, ?Carbon $date = null)
    {
        // Process tractors in batches for better performance
        return $tractors->map(function ($tractor) use ($date) {
            // Calculate for this tractor
            $startMovementTime = $this->detectStartMovementTime($tractor, $date);
            $tractor->calculated_start_work_time = $startMovementTime;
            return $tractor;
        });
    }

    /**
     * Generate cache key for a specific tractor and date
     *
     * @param int $tractorId
     * @param Carbon $date
     * @return string
     */
    private function getCacheKey(int $tractorId, Carbon $date): string
    {
        return "tractor_start_movement_time_{$tractorId}_{$date->format('Y-m-d')}";
    }

    /**
     * Get optimized GPS data query with proper indexing hints
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
     * Find the first point in the first 3 consecutive movement points.
     * Uses the same algorithm as TractorPathService::findFirstMovementPointIdFromOriginalData()
     *
     * Rules:
     * - Movement = status == 1 AND speed > 0
     * - Need 3 consecutive movement points
     * - Return the first point of that sequence
     *
     * @param \Illuminate\Support\Collection $gpsData Original GPS data (all points)
     * @return object|null The first movement point, or null if not found
     */
    private function findFirstMovementPoint($gpsData): ?object
    {
        $consecutiveMovementCount = 0;
        $firstMovementPoint = null;

        foreach ($gpsData as $point) {
            // Strict rule: Movement = status == 1 AND speed > 0 (same as TractorPathService)
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

                // Found 3 consecutive movement points - return the first point
                if ($consecutiveMovementCount >= 3) {
                    return $firstMovementPoint;
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
     * Legacy method for backward compatibility - calculates only start work time
     *
     * @param Tractor $tractor
     * @param Carbon|null $date
     * @return string|null
     */
    public function calculateStartWorkTime(Tractor $tractor, ?Carbon $date = null): ?string
    {
        return $this->detectStartMovementTime($tractor, $date);
    }

    /**
     * Legacy method for backward compatibility - calculates start work time for multiple tractors
     *
     * @param \Illuminate\Support\Collection $tractors
     * @param Carbon|null $date
     * @return \Illuminate\Support\Collection
     */
    public function calculateStartWorkTimeForTractors($tractors, ?Carbon $date = null)
    {
        return $this->detectStartMovementTimeForTractors($tractors, $date);
    }
}

