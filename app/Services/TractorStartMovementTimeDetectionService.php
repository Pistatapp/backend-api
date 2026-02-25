<?php

namespace App\Services;

use App\Models\Tractor;
use App\Traits\GpsReadConnection;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TractorStartMovementTimeDetectionService
{
    use GpsReadConnection;
    private const CACHE_TTL = 3600; // 1 hour
    private const REQUIRED_CONSECUTIVE_POINTS = 3;
    private const GPS_DATA_CHUNK_SIZE = 500;

    /**
     * Detect start movement time for a tractor using GPS data analysis.
     * Finds the first 3 consecutive movement points (status=1 AND speed>0) AFTER the tractor's
     * user-specified start_work_time, then returns the time of the first point in that sequence.
     *
     * @param Tractor $tractor
     * @param Carbon|null $date Optional date to analyze (defaults to today)
     * @return string|null Start movement time in H:i:s format, or null if not found
     */
    public function detectStartMovementTime(Tractor $tractor, ?Carbon $date = null): ?string
    {
        $targetDate = $date ?? Carbon::today();
        $cacheKey = $this->getCacheKey($tractor->id, $targetDate);

        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached === 'null' ? null : $cached;
        }

        try {
            $workStartTime = $this->getWorkStartDateTime($tractor, $targetDate);
            $startTime = $this->findStartMovementTime($tractor->id, $targetDate, $workStartTime);
            Cache::put($cacheKey, $startTime ?? 'null', self::CACHE_TTL);
            return $startTime;
        } catch (\Exception $e) {
            Log::error("Failed to detect start movement time for tractor {$tractor->id}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Detect start movement time for multiple tractors (optimized batch processing).
     *
     * @param Collection $tractors
     * @param Carbon|null $date Optional date to analyze (defaults to today)
     * @return Collection
     */
    public function detectStartMovementTimeForTractors(Collection $tractors, ?Carbon $date = null): Collection
    {
        $targetDate = $date ?? Carbon::today();

        $cacheKeys = $tractors->mapWithKeys(
            fn($t) => [$t->id => $this->getCacheKey($t->id, $targetDate)]
        )->toArray();

        $cachedResults = Cache::many(array_values($cacheKeys));

        // Map cache results back to tractor IDs
        $tractorResults = [];
        foreach ($cacheKeys as $tractorId => $cacheKey) {
            if (isset($cachedResults[$cacheKey])) {
                $tractorResults[$tractorId] = $cachedResults[$cacheKey];
            }
        }

        $tractorsNeedingCalculation = $tractors->filter(fn($t) => !isset($tractorResults[$t->id]));

        if ($tractorsNeedingCalculation->isNotEmpty()) {
            $this->bulkDetectStartMovementTime($tractorsNeedingCalculation, $targetDate);
            $cachedResults = Cache::many(array_values($cacheKeys));
            foreach ($cacheKeys as $tractorId => $cacheKey) {
                if (isset($cachedResults[$cacheKey])) {
                    $tractorResults[$tractorId] = $cachedResults[$cacheKey];
                }
            }
        }

        return $tractors->map(function ($tractor) use ($tractorResults) {
            $tractor->calculated_start_work_time = $this->normalizeCacheValue($tractorResults[$tractor->id] ?? null);
            return $tractor;
        });
    }

    /**
     * Find start movement time using optimized chunked SQL query.
     * Only considers GPS data AFTER the work start time.
     * Uses read-optimized connection with READ UNCOMMITTED isolation
     * to prevent write operations from blocking reads.
     *
     * @param int $tractorId
     * @param Carbon $date
     * @param Carbon $workStartTime The tractor's user-specified work start time
     * @return string|null
     */
    private function findStartMovementTime(int $tractorId, Carbon $date, Carbon $workStartTime): ?string
    {
        $startDateTime = $workStartTime->format('Y-m-d H:i:s');
        $endDateTime = $date->copy()->addDay()->format('Y-m-d');
        $consecutiveCount = 0;
        $firstMovementTime = null;

        for ($offset = 0; ; $offset += self::GPS_DATA_CHUNK_SIZE) {
            $chunk = $this->gpsReadSelect("
                SELECT status, speed, date_time
                FROM gps_data
                WHERE tractor_id = ? AND date_time >= ? AND date_time < ?
                ORDER BY date_time ASC
                LIMIT ? OFFSET ?
            ", [$tractorId, $startDateTime, $endDateTime, self::GPS_DATA_CHUNK_SIZE, $offset]);

            if (empty($chunk)) {
                break;
            }

            foreach ($chunk as $point) {
                if ($this->isMovement($point)) {
                    $firstMovementTime ??= $point->date_time;
                    if (++$consecutiveCount >= self::REQUIRED_CONSECUTIVE_POINTS) {
                        return Carbon::parse($firstMovementTime)->format('H:i:s');
                    }
                } else {
                    $consecutiveCount = 0;
                    $firstMovementTime = null;
                }
            }

            if (count($chunk) < self::GPS_DATA_CHUNK_SIZE) {
                break;
            }
        }

        return null;
    }

    /**
     * Bulk detect start movement time for multiple tractors.
     * Each tractor's GPS data is filtered by its own work start time.
     *
     * @param Collection $tractors
     * @param Carbon $date
     * @return void
     */
    private function bulkDetectStartMovementTime(Collection $tractors, Carbon $date): void
    {
        $tractorIds = $tractors->pluck('id')->filter()->unique()->values()->toArray();

        if (empty($tractorIds)) {
            return;
        }

        // Build maps: tractor -> work start time
        $tractorToWorkStart = [];
        foreach ($tractors as $tractor) {
            $tractorToWorkStart[$tractor->id] = $this->getWorkStartDateTime($tractor, $date);
        }

        $dateStr = $date->format('Y-m-d');
        $endDateTime = $date->copy()->addDay()->format('Y-m-d');
        $placeholders = implode(',', array_fill(0, count($tractorIds), '?'));

        // Fetch all GPS data for the day (we'll filter by work start time per tractor)
        // Uses read-optimized connection with READ UNCOMMITTED isolation
        $allGpsData = $this->gpsReadSelect("
            SELECT tractor_id, status, speed, date_time
            FROM gps_data
            WHERE tractor_id IN ({$placeholders}) AND date_time >= ? AND date_time < ?
            ORDER BY tractor_id, date_time ASC
        ", array_merge($tractorIds, [$dateStr, $endDateTime]));

        $groupedData = collect($allGpsData)->groupBy('tractor_id');
        $results = [];

        foreach ($tractorIds as $tractorId) {
            if (!isset($tractorToWorkStart[$tractorId])) {
                continue;
            }

            $workStartTime = $tractorToWorkStart[$tractorId];
            $points = $groupedData->get($tractorId, collect())
                ->filter(fn($p) => Carbon::parse($p->date_time)->gte($workStartTime))
                ->values()
                ->toArray();

            $startTime = $this->findFirstMovementTimeFromPoints($points);
            $results[$this->getCacheKey($tractorId, $date)] = $startTime ?? 'null';
        }

        if (!empty($results)) {
            Cache::putMany($results, self::CACHE_TTL);
        }
    }

    /**
     * Find first movement time from array of GPS points.
     *
     * @param array $points
     * @return string|null
     */
    private function findFirstMovementTimeFromPoints(array $points): ?string
    {
        $consecutiveCount = 0;
        $firstMovementTime = null;

        foreach ($points as $point) {
            if ($this->isMovement($point)) {
                $firstMovementTime ??= $point->date_time;
                if (++$consecutiveCount >= self::REQUIRED_CONSECUTIVE_POINTS) {
                    return Carbon::parse($firstMovementTime)->format('H:i:s');
                }
            } else {
                $consecutiveCount = 0;
                $firstMovementTime = null;
            }
        }

        return null;
    }

    /**
     * Check if a GPS point represents movement (status=1 AND speed>0).
     *
     * @param object $point
     * @return bool
     */
    private function isMovement(object $point): bool
    {
        return (int)$point->status === 1 && (int)$point->speed > 0;
    }

    /**
     * Normalize cache value (convert 'null' string to actual null).
     *
     * @param string|null $value
     * @return string|null
     */
    private function normalizeCacheValue(?string $value): ?string
    {
        return $value === 'null' || $value === null ? null : $value;
    }

    /**
     * Get the work start datetime for a tractor.
     * Uses tractor's start_work_time if set, otherwise defaults to start of day.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return Carbon
     */
    private function getWorkStartDateTime(Tractor $tractor, Carbon $date): Carbon
    {
        if ($tractor->start_work_time) {
            return $date->copy()->setTimeFromTimeString($tractor->start_work_time);
        }

        return $date->copy()->startOfDay();
    }

    /**
     * Generate cache key for tractor and date.
     *
     * @param int $tractorId
     * @param Carbon $date
     * @return string
     */
    private function getCacheKey(int $tractorId, Carbon $date): string
    {
        return "tractor_start_time:{$tractorId}:{$date->format('Y-m-d')}";
    }
}

