<?php

namespace App\Traits;

use App\Models\GpsReport;
use App\Models\Tractor;
use Illuminate\Support\Facades\Cache;

trait TractorWorkingTime
{
    /**
     * The tractor associated with this device.
     *
     * @var Tractor|null
     */
    protected $tractor;

    private const SPEED_THRESHOLD = 5; // km/h, minimum speed to consider as movement
    private const WINDOW_SIZE = 3; // Number of reports to analyze in the sliding window
    private const CACHE_TTL = 1440; // 24 hours in minutes

    /**
     * Detect and set working times based on a GPS report
     *
     * @param GpsReport $report
     * @return void
     */
    public function setWorkingTimes(GpsReport $report): void
    {
        if ($this->isWithinWorkingHours($report)) {
            $this->detectStartEndPoints($report);
        }
    }

    /**
     * Detect the start and end points of the tractor's working time based on the GPS report.
     *
     * @param GpsReport $report
     * @return void
     */
    private function detectStartEndPoints(GpsReport $report): void
    {
        $cacheKey = "tractor_points_{$this->tractor->id}_{$report->date_time->toDateString()}";

        $points = Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL), function () use ($report) {
            return [
                'start' => $this->hasStartPointForToday($report),
                'end' => $this->hasEndPointForToday($report)
            ];
        });

        if ($points['start'] && $points['end']) {
            return;
        }

        $reportsCacheKey = "tractor_reports_{$this->tractor->id}_{$report->date_time->toDateString()}";
        $surroundingReports = Cache::remember($reportsCacheKey, now()->addMinutes(5), function () use ($report) {
            return $this->device->reports()
                ->whereDate('date_time', $report->date_time->toDateString())
                ->orderBy('date_time')
                ->get();
        });

        if (!$surroundingReports->contains('id', $report->id)) {
            $surroundingReports->push($report);
            $surroundingReports = $surroundingReports->sortBy('date_time')->values();
            Cache::put($reportsCacheKey, $surroundingReports, now()->addMinutes(5));
        }

        $currentIndex = $surroundingReports->search(function ($item) use ($report) {
            return $item->id === $report->id;
        });

        if ($currentIndex !== false) {
            if (!$points['start'] && $currentIndex >= 1) {
                for ($i = 1; $i < $surroundingReports->count(); $i++) {
                    $prevReport = $surroundingReports[$i - 1];
                    $currReport = $surroundingReports[$i];

                    $isTransitionToMoving = $prevReport->speed < self::SPEED_THRESHOLD &&
                        $currReport->speed >= self::SPEED_THRESHOLD;

                    if ($isTransitionToMoving) {
                        $sustainedMovement = true;
                        for ($j = 1; $j < self::WINDOW_SIZE && ($i + $j) < $surroundingReports->count(); $j++) {
                            if ($surroundingReports[$i + $j]->speed < self::SPEED_THRESHOLD) {
                                $sustainedMovement = false;
                                break;
                            }
                        }

                        if ($sustainedMovement) {
                            $currReport->update(['is_starting_point' => true]);
                            Cache::put($cacheKey, [
                                'start' => true,
                                'end' => $points['end']
                            ], now()->addMinutes(self::CACHE_TTL));
                            break;
                        }
                    }
                }
            }

            if (!$points['end']) {
                if ($currentIndex >= self::WINDOW_SIZE - 1) {
                    $window = $surroundingReports->slice($currentIndex - self::WINDOW_SIZE + 1, self::WINDOW_SIZE);

                    $isEndPoint = $window->slice(0, -1)->every(function ($r) {
                        return $r->speed >= self::SPEED_THRESHOLD;
                    }) &&
                        $window->last()->speed < self::SPEED_THRESHOLD;

                    if ($isEndPoint) {
                        $report->update(['is_ending_point' => true]);
                        Cache::put($cacheKey, [
                            'start' => $points['start'],
                            'end' => true
                        ], now()->addMinutes(self::CACHE_TTL));
                    }
                }
            }
        }
    }

    /**
     * Check if the report has a starting point for today.
     *
     * @param GpsReport $report
     * @return bool
     */
    private function hasStartPointForToday(GpsReport $report): bool
    {
        $cacheKey = "start_point_{$this->tractor->id}_{$report->date_time->toDateString()}";

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL), function () use ($report) {
            return $this->device->reports()
                ->whereDate('date_time', $report->date_time->toDateString())
                ->where('is_starting_point', true)
                ->exists();
        });
    }

    /**
     * Check if there is an ending point for the tractor on the given date.
     *
     * @param GpsReport $report
     * @return bool
     */
    private function hasEndPointForToday(GpsReport $report): bool
    {
        $cacheKey = "end_point_{$this->tractor->id}_{$report->date_time->toDateString()}";

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL), function () use ($report) {
            return $this->device->reports()
                ->whereDate('date_time', $report->date_time->toDateString())
                ->where('is_ending_point', true)
                ->exists();
        });
    }

    /**
     * Check if the report's date_time is within the working hours of the tractor.
     *
     * @param GpsReport|array $report
     * @return bool
     */
    private function isWithinWorkingHours(GpsReport|array $report): bool
    {
        $dateTime = $report['date_time'];

        $workingHoursCacheKey = "tractor_working_hours_{$this->tractor->id}_{$dateTime->toDateString()}";

        $workingHours = Cache::remember($workingHoursCacheKey, now()->addMinutes(self::CACHE_TTL), function () {
            return [
                'start' => today()->setTimeFromTimeString($this->tractor->start_work_time),
                'end' => today()->setTimeFromTimeString($this->tractor->end_work_time)
            ];
        });

        return $dateTime->between($workingHours['start'], $workingHours['end']);
    }
}
