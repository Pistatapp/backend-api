<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TractorStartMovementTimeDetectionService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const REQUIRED_CONSECUTIVE_POINTS = 3;
    private const GPS_DATA_CHUNK_SIZE = 500;

    /**
     * Detect start movement time for a tractor using GPS data analysis.
     * Finds the first point in the first 3 consecutive movement points (status=1 AND speed>0).
     *
     * @param Tractor $tractor
     * @param Carbon|null $date Optional date to analyze (defaults to today)
     * @return string|null Start movement time in H:i:s format, or null if not found
     */
    public function detectStartMovementTime(Tractor $tractor, ?Carbon $date = null): ?string
    {
        if (!$tractor->gpsDevice) {
            return null;
        }

        $targetDate = $date ?? Carbon::today();
        $cacheKey = $this->getCacheKey($tractor->id, $targetDate);

        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached === 'null' ? null : $cached;
        }

        try {
            $startTime = $this->findStartMovementTime($tractor->gpsDevice->id, $targetDate);
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
        $tractorsWithGps = $tractors->filter(fn($t) => $t->gpsDevice !== null);

        if ($tractorsWithGps->isEmpty()) {
            return $tractors->each(fn($t) => $t->calculated_start_work_time = null);
        }

        $cacheKeys = $tractorsWithGps->mapWithKeys(
            fn($t) => [$t->id => $this->getCacheKey($t->id, $targetDate)]
        )->toArray();

        $cachedResults = Cache::many($cacheKeys);
        $tractorsNeedingCalculation = $tractorsWithGps->filter(fn($t) => !isset($cachedResults[$t->id]));

        if ($tractorsNeedingCalculation->isNotEmpty()) {
            $this->bulkDetectStartMovementTime($tractorsNeedingCalculation, $targetDate);
            $cachedResults = Cache::many($cacheKeys);
        }

        return $tractors->map(function ($tractor) use ($cachedResults) {
            $tractor->calculated_start_work_time = $this->normalizeCacheValue($cachedResults[$tractor->id] ?? null);
            return $tractor;
        });
    }

    /**
     * Find start movement time using optimized chunked SQL query.
     *
     * @param int $gpsDeviceId
     * @param Carbon $date
     * @return string|null
     */
    private function findStartMovementTime(int $gpsDeviceId, Carbon $date): ?string
    {
        $dateStr = $date->format('Y-m-d');
        $consecutiveCount = 0;
        $firstMovementTime = null;

        for ($offset = 0; ; $offset += self::GPS_DATA_CHUNK_SIZE) {
            $chunk = DB::select("
                SELECT status, speed, date_time
                FROM gps_data
                WHERE gps_device_id = ? AND date_time >= ? AND date_time < DATE_ADD(?, INTERVAL 1 DAY)
                ORDER BY date_time ASC
                LIMIT ? OFFSET ?
            ", [$gpsDeviceId, $dateStr, $dateStr, self::GPS_DATA_CHUNK_SIZE, $offset]);

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
     *
     * @param Collection $tractors
     * @param Carbon $date
     * @return void
     */
    private function bulkDetectStartMovementTime(Collection $tractors, Carbon $date): void
    {
        $gpsDeviceIds = $tractors->pluck('gpsDevice.id')->filter()->unique()->values()->toArray();

        if (empty($gpsDeviceIds)) {
            return;
        }

        $deviceToTractor = $tractors->mapWithKeys(fn($t) => [$t->gpsDevice->id => $t->id])->toArray();
        $dateStr = $date->format('Y-m-d');
        $placeholders = implode(',', array_fill(0, count($gpsDeviceIds), '?'));

        $allGpsData = DB::select("
            SELECT gps_device_id, status, speed, date_time
            FROM gps_data
            WHERE gps_device_id IN ({$placeholders}) AND date_time >= ? AND date_time < DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY gps_device_id, date_time ASC
        ", array_merge($gpsDeviceIds, [$dateStr, $dateStr]));

        $groupedData = collect($allGpsData)->groupBy('gps_device_id');
        $results = [];

        foreach ($gpsDeviceIds as $deviceId) {
            if (!isset($deviceToTractor[$deviceId])) {
                continue;
            }

            $startTime = $this->findFirstMovementTimeFromPoints($groupedData->get($deviceId, collect())->toArray());
            $results[$this->getCacheKey($deviceToTractor[$deviceId], $date)] = $startTime ?? 'null';
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

