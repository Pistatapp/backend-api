<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TractorWorkTimeDetectionService
{
    private const MIN_SPEED_FOR_WORK = 1; // Minimum speed to consider as actual work

    public function __construct() {}

    /**
     * Detect all work time events for a tractor using GPS data analysis
     * Returns on_time, start_work_time, and end_work_time
     *
     * @param Tractor $tractor
     * @param Carbon|null $date Optional date to analyze (defaults to today)
     * @return array{on_time: string|null, start_work_time: string|null, end_work_time: string|null}
     */
    public function detectWorkTimes(Tractor $tractor, ?Carbon $date = null): array
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

        try {
            // Get user-specified working hours
            $userStartTime = $tractor->start_work_time;
            $userEndTime = $tractor->end_work_time;

            if (!$userStartTime || !$userEndTime) {
                // If no working hours specified, return null for all times
                return [
                    'on_time' => null,
                    'start_work_time' => null,
                    'end_work_time' => null
                ];
            }

            // Get GPS data with optimized query
            $gpsData = $this->getOptimizedGpsData($tractor, $targetDate);

            if ($gpsData->isEmpty()) {
                return [
                    'on_time' => null,
                    'start_work_time' => null,
                    'end_work_time' => null
                ];
            }

            // Calculate work time boundaries
            $dateString = $targetDate->toDateString();
            $workStartToday = Carbon::parse($dateString . ' ' . $userStartTime);
            $workEndToday = Carbon::parse($dateString . ' ' . $userEndTime);

            // Detect all work times
            return $this->detectWorkTimeEvents($gpsData, $workStartToday, $workEndToday);
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
     * @return \Illuminate\Support\Collection
     */
    public function detectWorkTimesForTractors($tractors, ?Carbon $date = null)
    {
        // Process tractors in batches for better performance
        return $tractors->map(function ($tractor) use ($date) {
            // Calculate for this tractor
            $workTimes = $this->detectWorkTimes($tractor, $date);
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
        return $tractor->gpsData()->whereDate('date_time', $date)
            ->orderBy('date_time')
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

        // Process GPS data in chronological order to detect state transitions
        foreach ($gpsData as $point) {
            $pointTime = Carbon::parse($point->date_time);

            // Detect on_time: the very first point with status 1 right after user specified tractor start time
            if ($onTime === null &&
                $pointTime->gte($workStartToday) &&
                $point->status == 1) {
                $onTime = $pointTime->format('H:i:s');
            }

            // Detect start_work_time: the very first point with status 1 and speed more than 2 after user specified start work time
            if ($startWorkTime === null &&
                $pointTime->gte($workStartToday) &&
                $point->status == 1 &&
                $point->speed > self::MIN_SPEED_FOR_WORK) {
                $startWorkTime = $pointTime->format('H:i:s');
            }

            // Detect end_work_time: the very first point with status 0 and speed 0, after user specified tractor end_work_time
            if ($endWorkTime === null &&
                $pointTime->gt($workEndToday) &&
                $point->status == 0 &&
                $point->speed == 0) {
                $endWorkTime = $pointTime->format('H:i:s');
            }

            // Early exit if we've found all required times
            if ($onTime !== null && $startWorkTime !== null && $endWorkTime !== null) {
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
