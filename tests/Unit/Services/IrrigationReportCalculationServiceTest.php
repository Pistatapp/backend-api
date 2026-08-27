<?php

namespace Tests\Unit\Services;

use App\Services\IrrigationReportCalculationService;
use Carbon\Carbon;
use Tests\TestCase;

class IrrigationReportCalculationServiceTest extends TestCase
{
    private IrrigationReportCalculationService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(IrrigationReportCalculationService::class);
    }

    /** T1: dripper count × flow × hours is the only volume formula. */
    public function test_t1_calculates_volume_from_drippers_flow_and_duration(): void
    {
        $liters = $this->calculator->volumeLiters([
            (object) ['dripper_count' => 500, 'dripper_flow_rate' => 4],
            (object) ['dripper_count' => 300, 'dripper_flow_rate' => 2],
        ], 2 * 3600);

        $this->assertEqualsWithDelta(5_200, $liters, 0.0001);
    }

    /** T1: single kart m³/ha = volume / irrigation_area ha. */
    public function test_t1_single_kart_volume_per_hectare(): void
    {
        $this->assertEqualsWithDelta(
            200.0,
            $this->calculator->volumePerHectareFromHa(100.0, 0.5),
            0.0001
        );
    }

    /** T2: multiple karts sum unique irrigation areas. */
    public function test_t2_multiple_karts_sum_unique_irrigation_areas(): void
    {
        $area = $this->calculator->irrigatedAreaHectares([
            (object) ['plot_id' => 1, 'irrigation_area' => 0.5],
            (object) ['plot_id' => 2, 'irrigation_area' => 0.4],
            (object) ['plot_id' => 3, 'irrigation_area' => 0.7],
        ]);

        $this->assertEqualsWithDelta(1.6, $area, 0.0001);
        $this->assertEqualsWithDelta(
            345.0,
            $this->calculator->volumePerHectareFromHa(552.0, $area),
            0.0001
        );
    }

    /** T3: period m³/ha uses total volume / sum of hectare-occurrences, not sum of daily m³/ha. */
    public function test_t3_period_intensity_is_not_sum_of_daily_m3_per_ha(): void
    {
        $days = [
            ['volume' => 200.0, 'area' => 1.0],
            ['volume' => 160.0, 'area' => 0.8],
            ['volume' => 150.0, 'area' => 0.5],
        ];

        $dailyIntensities = array_map(
            fn (array $day) => $this->calculator->volumePerHectareFromHa($day['volume'], $day['area']),
            $days
        );

        $totalVolume = array_sum(array_column($days, 'volume'));
        $totalArea = array_sum(array_column($days, 'area'));
        $period = $this->calculator->volumePerHectareFromHa($totalVolume, $totalArea);

        $this->assertEqualsWithDelta(200.0, $dailyIntensities[0], 0.0001);
        $this->assertEqualsWithDelta(200.0, $dailyIntensities[1], 0.0001);
        $this->assertEqualsWithDelta(300.0, $dailyIntensities[2], 0.0001);
        $this->assertEqualsWithDelta(510.0, $totalVolume, 0.0001);
        $this->assertEqualsWithDelta(2.3, $totalArea, 0.0001);
        $this->assertEqualsWithDelta(221.7391304348, $period, 0.00001);
        $this->assertNotEqualsWithDelta(700.0, $period, 0.001);
        $this->assertNotEqualsWithDelta(array_sum($dailyIntensities), $period, 0.001);
    }

    /** T4: same Plot referenced twice contributes area once. */
    public function test_t4_duplicate_plot_area_is_counted_once(): void
    {
        $area = $this->calculator->irrigatedAreaHectares([
            (object) ['id' => 10, 'plot_id' => 44, 'irrigation_area' => 0.5],
            (object) ['id' => 11, 'plot_id' => 44, 'irrigation_area' => 0.5],
            (object) ['id' => 12, 'plot_id' => 45, 'irrigation_area' => 0.4],
        ]);

        $this->assertEqualsWithDelta(0.9, $area, 0.0001);
    }

    /** T5: zero/empty irrigated area has no valid per-hectare result. */
    public function test_t5_returns_null_per_hectare_for_zero_area(): void
    {
        $this->assertNull($this->calculator->volumePerHectareFromHa(10.0, 0.0));
        $this->assertNull($this->calculator->volumePerHectareFromHa(10.0, -1.0));
        $this->assertNull($this->calculator->volumePerHectare(10.0, 0.0));
    }

    /** T2/T7: report dates are inclusive days represented by a half-open range. */
    public function test_report_range_includes_to_date_without_including_the_next_day(): void
    {
        [$rangeStart, $rangeEnd] = $this->calculator->reportRange(
            Carbon::parse('2026-08-10', 'Asia/Tehran'),
            Carbon::parse('2026-08-12', 'Asia/Tehran'),
        );

        $this->assertSame('2026-08-10 00:00:00', $rangeStart->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-13 00:00:00', $rangeEnd->format('Y-m-d H:i:s'));
        $this->assertSame(
            3600,
            $this->calculator->overlapSeconds(
                Carbon::parse('2026-08-12 23:00:00', 'Asia/Tehran'),
                Carbon::parse('2026-08-13 01:00:00', 'Asia/Tehran'),
                $rangeStart,
                $rangeEnd,
            )
        );
    }

    /** Multi-day overlap clipping remains exact. */
    public function test_cross_midnight_program_is_clipped_to_each_day(): void
    {
        $start = Carbon::parse('2026-08-10 23:00:00', 'Asia/Tehran');
        $end = Carbon::parse('2026-08-11 02:00:00', 'Asia/Tehran');
        [$firstDayStart, $firstDayEnd] = $this->calculator->reportRange($start, $start);
        [$secondDayStart, $secondDayEnd] = $this->calculator->reportRange($end, $end);

        $this->assertSame(3600, $this->calculator->overlapSeconds($start, $end, $firstDayStart, $firstDayEnd));
        $this->assertSame(7200, $this->calculator->overlapSeconds($start, $end, $secondDayStart, $secondDayEnd));
    }

    /** Duration helpers remain null-safe. */
    public function test_duration_is_exact_and_invalid_intervals_are_zero(): void
    {
        $start = Carbon::parse('2026-08-10 10:00:00', 'Asia/Tehran');
        $end = Carbon::parse('2026-08-10 11:05:07', 'Asia/Tehran');

        $this->assertSame(3907, $this->calculator->durationSeconds($start, $end));
        $this->assertSame(0, $this->calculator->durationSeconds($end, $start));
        $this->assertSame(0, $this->calculator->durationSeconds(null, $end));
    }

    /** Exact volume is preserved before presentation rounding. */
    public function test_keeps_fractional_liters_until_the_api_boundary(): void
    {
        $liters = $this->calculator->volumeLiters([
            (object) ['dripper_count' => 7, 'dripper_flow_rate' => 1.25],
        ], 1_001);

        $this->assertEqualsWithDelta(2.4329861111, $liters, 0.0000001);
    }
}
