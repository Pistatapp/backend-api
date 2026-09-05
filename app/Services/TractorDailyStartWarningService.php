<?php

namespace App\Services;

use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Notifications\TractorNotStartedTodayNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Finds configured/assigned tractors with no GPS log before the daily cutoff.
 *
 * GPS data is queried through the read connection only. The service never
 * updates tractors or telemetry, and the cache key makes the manager alert
 * idempotent if the scheduler is invoked more than once for the same day.
 */
class TractorDailyStartWarningService
{
    private const CUTOFF_HOUR = 9;
    private const TIMEZONE = 'Asia/Tehran';

    public function checkAndNotify(?Carbon $now = null): int
    {
        $now = ($now ?: now())->copy()->setTimezone(self::TIMEZONE);
        $cutoff = $now->copy()->startOfDay()->setTime(self::CUTOFF_HOUR, 0);

        if (! $this->isDue($now)) {
            return 0;
        }

        $tractors = Tractor::query()
            ->with(['farm.admins', 'farm.owner', 'gpsDevice'])
            ->where('is_in_repair_shop', false)
            ->whereHas('driver')
            ->whereHas('gpsDevice', fn ($query) => $query->whereNotNull('imei'))
            ->get();

        $missing = $this->findMissingGpsLogs(
            $tractors,
            fn (Tractor $tractor): bool => $this->hasGpsLogBefore($tractor->gpsDevice, $cutoff)
        );

        $sent = 0;
        foreach ($missing as $tractor) {
            $recipients = $this->managerRecipients($tractor);
            if ($recipients->isEmpty()) {
                continue;
            }

            $cacheKey = $this->alertCacheKey($tractor, $cutoff);
            if (Cache::has($cacheKey)) {
                continue;
            }

            Notification::send($recipients, new TractorNotStartedTodayNotification($tractor));
            // Mark only after Notification::send succeeds, so a provider or
            // queue dispatch failure can be retried by the scheduler.
            Cache::put($cacheKey, true, $cutoff->copy()->addDay());
            $sent += $recipients->count();
        }

        return $sent;
    }

    public function isDue(Carbon $now): bool
    {
        return $now->copy()->setTimezone(self::TIMEZONE)->hour >= self::CUTOFF_HOUR;
    }

    /**
     * Exposed as a pure selection step for deterministic unit tests.
     *
     * @param Collection<int, Tractor> $tractors
     * @param callable(Tractor): bool $hasLog
     * @return Collection<int, Tractor>
     */
    public function findMissingGpsLogs(Collection $tractors, callable $hasLog): Collection
    {
        return $tractors->filter(fn (Tractor $tractor): bool => ! $hasLog($tractor))->values();
    }

    private function hasGpsLogBefore(?GpsDevice $device, Carbon $cutoff): bool
    {
        if (! $device || ! $device->imei) {
            return false;
        }

        $start = $cutoff->copy()->startOfDay();
        $query = DB::connection('mysql_gps_read')
            ->table('gps_data')
            ->where('date_time', '>=', $start->format('Y-m-d H:i:s'))
            ->where('date_time', '<', $cutoff->format('Y-m-d H:i:s'))
            ->where(function ($query) use ($device) {
                // device_id is the authoritative relation in gps_data. IMEI
                // remains a safe compatibility match for older ingested rows.
                $query->where('device_id', $device->id)
                    ->orWhere('imei', $device->imei);
            });

        return $query->exists();
    }

    private function managerRecipients(Tractor $tractor): Collection
    {
        $farm = $tractor->farm;
        if (! $farm) {
            return collect();
        }

        return $farm->admins
            ->concat($farm->owner ? collect([$farm->owner]) : collect())
            ->filter(fn ($user) => filled($user->mobile))
            ->unique('id')
            ->values();
    }

    private function alertCacheKey(Tractor $tractor, Carbon $cutoff): string
    {
        return 'tractor-daily-start-warning:' . $tractor->id . ':' . $cutoff->format('Y-m-d');
    }
}
