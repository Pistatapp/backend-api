<?php

namespace App\Services\Tractor;

use App\Models\Tractor;
use App\Services\GpsDataAnalyzer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TractorWorkTimeDetectionService
{
    private const CACHE_PREFIX = 'tractor_work_time_detection';
    private const MIN_SPEED_FOR_WORK = 2; // Minimum speed to consider as actual work

    public function __construct(
        private GpsDataAnalyzer $gpsDataAnalyzer
    ) {}

    /**
     * Detect all work time events for a tractor using GPS data analysis
     * Returns on_time, start_work_time, and end_work_time
     * Automatically clears cache when new times are detected
     *
     * @param Tractor $tractor
     * @param Carbon|null $date Optional date to analyze (defaults to today)
     * @param bool $forceRefresh Force cache refresh even if cached data exists
     * @return array{on_time: string|null, start_work_time: string|null, end_work_time: string|null}
     */
    public function detectWorkTimes(Tractor $tractor, ?Carbon $date = null, bool $forceRefresh = false): array
    {
        // Early exit: Check if tractor has GPS device
        if (!$tractor->gpsDevice) {
            return [
                'on_time' => null,
                'start_work_time' => null,
                'end_work_time' => null
            ];
        }

        // Use provided date or default to today
        $targetDate = $date ?? Carbon::today();
        $dateString = $targetDate->toDateString();

        // Check cache first (unless force refresh is requested)
        $cacheKey = $this->getCacheKey($tractor->id, $dateString);
        $cachedResult = Cache::get($cacheKey);
        if ($cachedResult !== null && !$forceRefresh) {
            return $cachedResult;
        }

        try {
            // Get user-specified working hours
            $userStartTime = $tractor->start_work_time;
            $userEndTime = $tractor->end_work_time;

            if (!$userStartTime || !$userEndTime) {
                // If no working hours specified, return null for all times
                $result = [
                    'on_time' => null,
                    'start_work_time' => null,
                    'end_work_time' => null
                ];
                $cacheTtl = $this->getCacheTtlUntilEndOfDay($targetDate);
                Cache::put($cacheKey, $result, $cacheTtl);
                return $result;
            }

            // Get GPS data with optimized query
            $gpsData = $this->getOptimizedGpsData($tractor, $targetDate);

            if ($gpsData->isEmpty()) {
                $result = [
                    'on_time' => null,
                    'start_work_time' => null,
                    'end_work_time' => null
                ];
                $cacheTtl = $this->getCacheTtlUntilEndOfDay($targetDate);
                Cache::put($cacheKey, $result, $cacheTtl);
                return $result;
            }

            // Calculate work time boundaries
            $workStartToday = Carbon::parse($dateString . ' ' . $userStartTime);
            $workEndToday = Carbon::parse($dateString . ' ' . $userEndTime);

            // Detect all work times
            $result = $this->detectWorkTimeEvents($gpsData, $workStartToday, $workEndToday);

            // Check if we have new times detected and clear cache if needed
            $this->handleCacheInvalidation($tractor, $targetDate, $result, $cachedResult);

            // Cache the result until end of day
            $cacheTtl = $this->getCacheTtlUntilEndOfDay($targetDate);
            Cache::put($cacheKey, $result, $cacheTtl);

            return $result;

        } catch (\Exception $e) {
            // Log error and return null if analysis fails
            Log::error('Failed to detect work times for tractor ' . $tractor->id . ': ' . $e->getMessage());
            return [
                'on_time' => null,
                'start_work_time' => null,
                'end_work_time' => null
            ];
        }
    }

    /**
     * Detect work time events for multiple tractors (optimized batch processing)
     *
     * @param \Illuminate\Support\Collection $tractors
     * @param Carbon|null $date Optional date to analyze (defaults to today)
     * @param bool $forceRefresh Force cache refresh even if cached data exists
     * @return \Illuminate\Support\Collection
     */
    public function detectWorkTimesForTractors($tractors, ?Carbon $date = null, bool $forceRefresh = false)
    {
        $targetDate = $date ?? Carbon::today();
        $dateString = $targetDate->toDateString();

        // Batch cache check for all tractors (unless force refresh is requested)
        if (!$forceRefresh) {
            $tractorIds = $tractors->pluck('id')->toArray();
            $cacheKeys = array_map(fn($id) => $this->getCacheKey($id, $dateString), $tractorIds);
            $cachedResults = Cache::many($cacheKeys);
        } else {
            $cachedResults = [];
        }

        // Process tractors in batches for better performance
        return $tractors->map(function ($tractor) use ($date, $cachedResults, $dateString, $forceRefresh) {
            $cacheKey = $this->getCacheKey($tractor->id, $dateString);

            // Use cached result if available and not forcing refresh
            if (!$forceRefresh && isset($cachedResults[$cacheKey])) {
                $workTimes = $cachedResults[$cacheKey];
                $tractor->on_time = $workTimes['on_time'];
                $tractor->calculated_start_work_time = $workTimes['start_work_time'];
                $tractor->calculated_end_work_time = $workTimes['end_work_time'];
                return $tractor;
            }

            // Calculate for this tractor
            $workTimes = $this->detectWorkTimes($tractor, $date, $forceRefresh);
            $tractor->on_time = $workTimes['on_time'];
            $tractor->calculated_start_work_time = $workTimes['start_work_time'];
            $tractor->calculated_end_work_time = $workTimes['end_work_time'];
            return $tractor;
        });
    }

    /**
     * Get working time boundaries for a tractor on a specific date
     *
     * @param Tractor $tractor
     * @param Carbon|null $date Optional date (defaults to today)
     * @return array{start: Carbon|null, end: Carbon|null}
     */
    public function getWorkingTimeBoundaries(Tractor $tractor, ?Carbon $date = null): array
    {
        $targetDate = $date ?? Carbon::today();
        $dateString = $targetDate->toDateString();

        $userStartTime = $tractor->start_work_time;
        $userEndTime = $tractor->end_work_time;

        if (!$userStartTime || !$userEndTime) {
            return ['start' => null, 'end' => null];
        }

        return [
            'start' => Carbon::parse($dateString . ' ' . $userStartTime),
            'end' => Carbon::parse($dateString . ' ' . $userEndTime)
        ];
    }

    /**
     * Check if a given time falls within tractor's working hours
     *
     * @param Tractor $tractor
     * @param Carbon $time
     * @return bool
     */
    public function isWithinWorkingHours(Tractor $tractor, Carbon $time): bool
    {
        $boundaries = $this->getWorkingTimeBoundaries($tractor, $time);

        if (!$boundaries['start'] || !$boundaries['end']) {
            return false;
        }

        return $time->between($boundaries['start'], $boundaries['end']);
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
            ->select(['id', 'date_time', 'coordinate', 'speed', 'status', 'imei'])
            ->whereDate('date_time', $date)
            ->where('date_time', '>=', $date->startOfDay())
            ->where('date_time', '<=', $date->endOfDay())
            ->orderBy('date_time', 'asc')
            ->limit(10000) // Prevent excessive data loading
            ->get();
    }

    /**
     * Detect work time events from GPS data
     *
     * @param \Illuminate\Support\Collection $gpsData
     * @param Carbon $workStartToday
     * @param Carbon $workEndToday
     * @return array{on_time: string|null, start_work_time: string|null, end_work_time: string|null}
     */
    private function detectWorkTimeEvents($gpsData, Carbon $workStartToday, Carbon $workEndToday): array
    {
        $onTime = null;
        $startWorkTime = null;
        $endWorkTime = null;

        // Find the first point with status 1 after user specified start work time (on_time)
        foreach ($gpsData as $point) {
            $pointTime = Carbon::parse($point->date_time);

            if ($point->status == 1 && $pointTime->gte($workStartToday)) {
                $onTime = $pointTime->format('H:i:s');
                break;
            }
        }

        // Find the first point with status 1 and speed > 2 after on_time (actual start work time)
        foreach ($gpsData as $point) {
            $pointTime = Carbon::parse($point->date_time);

            if ($point->status == 1 &&
                $point->speed > self::MIN_SPEED_FOR_WORK &&
                $pointTime->gte($workStartToday)) {
                $startWorkTime = $pointTime->format('H:i:s');
                break;
            }
        }

        // Find the first point with status 0 and speed 0 after user specified end work time (end work time)
        foreach ($gpsData as $point) {
            $pointTime = Carbon::parse($point->date_time);

            if ($point->status == 0 &&
                $point->speed == 0 &&
                $pointTime->gte($workEndToday)) {
                $endWorkTime = $pointTime->format('H:i:s');
                break;
            }
        }

        return [
            'on_time' => $onTime,
            'start_work_time' => $startWorkTime,
            'end_work_time' => $endWorkTime
        ];
    }

    /**
     * Generate cache key for tractor and date
     *
     * @param int $tractorId
     * @param string $dateString
     * @return string
     */
    private function getCacheKey(int $tractorId, string $dateString): string
    {
        return self::CACHE_PREFIX . "_{$tractorId}_{$dateString}";
    }

    /**
     * Clear cache for a specific tractor and date
     *
     * @param int $tractorId
     * @param string|null $dateString
     * @return void
     */
    public function clearCache(int $tractorId, ?string $dateString = null): void
    {
        $dateString = $dateString ?? Carbon::today()->toDateString();
        $cacheKey = $this->getCacheKey($tractorId, $dateString);
        Cache::forget($cacheKey);
    }

    /**
     * Clear cache for all tractors on a specific date
     *
     * @param string|null $dateString
     * @return void
     */
    public function clearCacheForDate(?string $dateString = null): void
    {
        Cache::flush();
    }

    /**
     * Force refresh work times for a tractor (clears cache and recalculates)
     * Use this when new GPS data is received that might affect work times
     *
     * @param Tractor $tractor
     * @param Carbon|null $date
     * @return array{on_time: string|null, start_work_time: string|null, end_work_time: string|null}
     */
    public function forceRefreshWorkTimes(Tractor $tractor, ?Carbon $date = null): array
    {
        // Clear existing cache first
        $targetDate = $date ?? Carbon::today();
        $this->clearCache($tractor->id, $targetDate->toDateString());

        // Force refresh the work times
        return $this->detectWorkTimes($tractor, $date, true);
    }

    /**
     * Force refresh work times for multiple tractors
     * Use this when new GPS data is received that might affect work times
     *
     * @param \Illuminate\Support\Collection $tractors
     * @param Carbon|null $date
     * @return \Illuminate\Support\Collection
     */
    public function forceRefreshWorkTimesForTractors($tractors, ?Carbon $date = null)
    {
        $targetDate = $date ?? Carbon::today();
        $dateString = $targetDate->toDateString();

        // Clear cache for all tractors first
        foreach ($tractors as $tractor) {
            $this->clearCache($tractor->id, $dateString);
        }

        // Force refresh all work times
        return $this->detectWorkTimesForTractors($tractors, $date, true);
    }

    /**
     * Handle cache invalidation when new work times are detected
     * Clears cache progressively as each work time is detected
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @param array $newResult
     * @param array|null $cachedResult
     * @return void
     */
    private function handleCacheInvalidation(Tractor $tractor, Carbon $date, array $newResult, ?array $cachedResult): void
    {
        // If we have cached results, check for progressive detection
        if ($cachedResult !== null) {
            // Check if any new work time was detected
            $hasNewDetection =
                ($cachedResult['on_time'] === null && $newResult['on_time'] !== null) ||
                ($cachedResult['start_work_time'] === null && $newResult['start_work_time'] !== null) ||
                ($cachedResult['end_work_time'] === null && $newResult['end_work_time'] !== null);

            if ($hasNewDetection) {
                $this->clearCache($tractor->id, $date->toDateString());
            }
        }
    }

    /**
     * Calculate cache TTL until end of day
     *
     * @param Carbon $date
     * @return int Seconds until end of day
     */
    private function getCacheTtlUntilEndOfDay(Carbon $date): int
    {
        $endOfDay = $date->copy()->endOfDay();
        $now = Carbon::now();

        // If we're past the end of day, cache for 1 minute (shouldn't happen in normal usage)
        if ($now->gt($endOfDay)) {
            return 60;
        }

        return $now->diffInSeconds($endOfDay);
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
        $workTimes = $this->detectWorkTimes($tractor, $date);
        return $workTimes['start_work_time'];
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
        $tractorsWithWorkTimes = $this->detectWorkTimesForTractors($tractors, $date);

        return $tractorsWithWorkTimes->map(function ($tractor) {
            $tractor->calculated_start_work_time = $tractor->calculated_start_work_time;
            return $tractor;
        });
    }
}
