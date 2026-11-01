<?php

namespace Tests\Unit\Services;

use App\Models\Tractor;
use App\Services\TractorWorkTimeAccountingService;
use Carbon\Carbon;
use Tests\TestCase;

class TractorWorkTimeAccountingServiceTest extends TestCase
{
    public function test_duty_work_includes_movement_and_engine_on_stop_but_not_engine_off_stop(): void
    {
        $tractor = new Tractor([
            'start_work_time' => '07:00:00',
            'end_work_time' => '15:00:00',
            'expected_daily_work_time' => 8,
        ]);

        $points = [
            $this->point('2026-10-31 06:59:00', 10, 1),
            $this->point('2026-10-31 07:00:00', 10, 1),
            $this->point('2026-10-31 07:01:00', 0, 1),
            $this->point('2026-10-31 07:02:00', 0, 0),
            $this->point('2026-10-31 07:03:00', 0, 0),
            $this->point('2026-10-31 14:59:00', 10, 1),
            $this->point('2026-10-31 15:00:00', 10, 1),
            $this->point('2026-10-31 15:01:00', 10, 1),
        ];

        $result = app(TractorWorkTimeAccountingService::class)->calculateFromPoints(
            $points,
            $tractor,
            Carbon::parse('2026-10-31')
        );

        $this->assertSame(120, $result['duty_moving_seconds']);
        $this->assertSame(60, $result['duty_engine_on_stopped_seconds']);
        $this->assertSame(180, $result['duty_work_seconds']);
        $this->assertSame(120, $result['overtime_moving_seconds']);
        $this->assertSame(120, $result['overtime_seconds']);
        $this->assertSame(300, $result['total_countable_work_seconds']);
        $this->assertSame(60, $result['engine_off_stopped_seconds']);
        $this->assertSame(
            $result['duty_work_seconds'] + $result['overtime_seconds'],
            $result['total_countable_work_seconds']
        );
    }

    public function test_long_gap_is_unknown_and_duplicate_is_not_counted_twice(): void
    {
        $tractor = new Tractor([
            'start_work_time' => '07:00:00',
            'end_work_time' => '15:00:00',
        ]);

        $points = [
            $this->point('2026-10-31 07:00:00', 10, 1, 1),
            $this->point('2026-10-31 07:00:00', 10, 1, 1),
            $this->point('2026-10-31 07:01:00', 10, 1, 3),
            $this->point('2026-10-31 08:00:00', 10, 1, 4),
        ];

        $result = app(TractorWorkTimeAccountingService::class)->calculateFromPoints(
            $points,
            $tractor,
            Carbon::parse('2026-10-31')
        );

        $this->assertSame(1, $result['duplicate_gps_count']);
        $this->assertSame(1, $result['unknown_gap_count']);
        $this->assertSame(3540, $result['unknown_gap_seconds']);
        $this->assertSame(60, $result['duty_moving_seconds']);
    }

    public function test_schedule_fallback_supports_an_overnight_shift(): void
    {
        $tractor = new Tractor([
            'start_work_time' => '22:00:00',
            'end_work_time' => '06:00:00',
            'expected_daily_work_time' => null,
        ]);

        $points = [
            $this->point('2026-10-31 22:00:00', 10, 1, 1),
            $this->point('2026-10-31 22:01:00', 10, 1, 2),
            $this->point('2026-11-01 05:59:00', 10, 1, 3),
            $this->point('2026-11-01 06:00:00', 10, 1, 4),
        ];

        $result = app(TractorWorkTimeAccountingService::class)->calculateFromPoints(
            $points,
            $tractor,
            Carbon::parse('2026-10-31')
        );

        $this->assertSame(28800, $result['scheduled_duty_seconds']);
        $this->assertSame(120, $result['duty_work_seconds']);
    }

    public function test_out_of_order_input_is_reported_but_accounted_after_sorting(): void
    {
        $tractor = new Tractor([
            'start_work_time' => '07:00:00',
            'end_work_time' => '15:00:00',
        ]);

        $points = [
            $this->point('2026-10-31 07:01:00', 10, 1, 1),
            $this->point('2026-10-31 07:00:00', 10, 1, 2),
        ];

        $result = app(TractorWorkTimeAccountingService::class)->calculateFromPoints(
            $points,
            $tractor,
            Carbon::parse('2026-10-31')
        );

        $this->assertSame(1, $result['out_of_order_count']);
        $this->assertSame(60, $result['duty_moving_seconds']);
    }

    /** @return array<string,mixed> */
    private function point(string $dateTime, int $speed, int $status, int $id = 0): array
    {
        return [
            'id' => $id,
            'date_time' => $dateTime,
            'coordinate' => [35.7 + ($id / 100000), 51.4 + ($id / 100000)],
            'speed' => $speed,
            'status' => $status,
        ];
    }
}
