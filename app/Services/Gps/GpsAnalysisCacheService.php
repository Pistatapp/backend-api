<?php

namespace App\Services\Gps;

use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Service for caching GPS analysis state
 * Enables incremental processing by storing and retrieving analysis state
 */
class GpsAnalysisCacheService
{
    /**
     * Cache prefix for GPS analysis
     */
    private const CACHE_PREFIX = 'gps_analysis';

    /**
     * Default cache TTL (until end of day + 1 hour buffer)
     */
    private const DEFAULT_TTL_HOURS = 25;

    /**
     * Get cache key for a tractor on a specific date
     */
    public function getCacheKey(int $tractorId, Carbon $date): string
    {
        return sprintf('%s:%d:%s', self::CACHE_PREFIX, $tractorId, $date->toDateString());
    }

    /**
     * Get cached analysis state for a tractor on a specific date
     */
    public function getState(int $tractorId, Carbon $date): ?GpsAnalysisState
    {
        $key = $this->getCacheKey($tractorId, $date);
        $cached = Cache::get($key);

        if ($cached === null) {
            return null;
        }

        return GpsAnalysisState::fromArray($cached);
    }

    /**
     * Save analysis state to cache
     */
    public function saveState(int $tractorId, Carbon $date, GpsAnalysisState $state): void
    {
        $key = $this->getCacheKey($tractorId, $date);
        
        // Calculate TTL: until end of day + buffer
        $ttl = $this->calculateTtl($date);

        Cache::put($key, $state->toArray(), $ttl);
    }

    /**
     * Check if cached state exists
     */
    public function hasState(int $tractorId, Carbon $date): bool
    {
        return Cache::has($this->getCacheKey($tractorId, $date));
    }

    /**
     * Invalidate cached state for a tractor on a specific date
     */
    public function invalidate(int $tractorId, Carbon $date): bool
    {
        return Cache::forget($this->getCacheKey($tractorId, $date));
    }

    /**
     * Invalidate all cached states for a tractor (e.g., when work times change)
     */
    public function invalidateForTractor(int $tractorId): void
    {
        // Invalidate last 7 days of cache
        $today = Carbon::today();
        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->subDays($i);
            $this->invalidate($tractorId, $date);
        }
    }

    /**
     * Invalidate cache when tractor settings change (work times)
     */
    public function onTractorWorkTimeChanged(Tractor $tractor): void
    {
        $this->invalidateForTractor($tractor->id);
    }

    /**
     * Calculate TTL for cache entry
     * For current day: until end of day + 1 hour
     * For past days: 24 hours (data won't change)
     */
    private function calculateTtl(Carbon $date): int
    {
        $now = Carbon::now();
        $endOfDay = $date->copy()->endOfDay();

        if ($date->isToday()) {
            // For today, cache until end of day + 1 hour buffer
            $seconds = $endOfDay->diffInSeconds($now) + 3600;
            return max($seconds, 3600); // At least 1 hour
        }

        // For past days, cache for 24 hours
        return self::DEFAULT_TTL_HOURS * 3600;
    }

    /**
     * Get or create state (atomic operation with locking)
     * Prevents race conditions when multiple requests try to create state simultaneously
     */
    public function getOrCreateState(
        int $tractorId,
        Carbon $date,
        callable $createCallback
    ): GpsAnalysisState {
        $key = $this->getCacheKey($tractorId, $date);
        $lockKey = $key . ':lock';

        // Try to get existing state first (fast path)
        $cached = Cache::get($key);
        if ($cached !== null) {
            return GpsAnalysisState::fromArray($cached);
        }

        // Acquire lock for creating new state
        $lock = Cache::lock($lockKey, 10); // 10 second timeout

        try {
            if ($lock->get()) {
                // Double-check after acquiring lock
                $cached = Cache::get($key);
                if ($cached !== null) {
                    return GpsAnalysisState::fromArray($cached);
                }

                // Create new state
                $state = $createCallback();
                $this->saveState($tractorId, $date, $state);
                return $state;
            }

            // Lock not acquired, wait and retry
            usleep(100000); // 100ms
            $cached = Cache::get($key);
            if ($cached !== null) {
                return GpsAnalysisState::fromArray($cached);
            }

            // Fallback: create without lock
            return $createCallback();
        } finally {
            $lock?->release();
        }
    }

    /**
     * Update state atomically with locking
     */
    public function updateState(
        int $tractorId,
        Carbon $date,
        callable $updateCallback
    ): GpsAnalysisState {
        $key = $this->getCacheKey($tractorId, $date);
        $lockKey = $key . ':lock';

        $lock = Cache::lock($lockKey, 10);

        try {
            if ($lock->get()) {
                $currentState = $this->getState($tractorId, $date) ?? new GpsAnalysisState();
                $updatedState = $updateCallback($currentState);
                $this->saveState($tractorId, $date, $updatedState);
                return $updatedState;
            }

            // Lock not acquired, proceed without lock (best effort)
            $currentState = $this->getState($tractorId, $date) ?? new GpsAnalysisState();
            return $updateCallback($currentState);
        } finally {
            $lock?->release();
        }
    }
}

