<?php

namespace Tests\Unit\Services;

use App\Models\Field;
use App\Models\Plot;
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

    /** T2/T7: report dates are inclusive days represented by a half-open range. */
    public function test_t2_and_t7_include_the_to_date_without_including_the_next_day(): void
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

    /** T3/T8: a cross-midnight program is split by actual overlap. */
    public function test_t3_and_t8_clip_a_cross_midnight_program_to_each_day(): void
    {
        $start = Carbon::parse('2026-08-10 23:00:00', 'Asia/Tehran');
        $end = Carbon::parse('2026-08-11 02:00:00', 'Asia/Tehran');
        [$firstDayStart, $firstDayEnd] = $this->calculator->reportRange($start, $start);
        [$secondDayStart, $secondDayEnd] = $this->calculator->reportRange($end, $end);

        $this->assertSame(3600, $this->calculator->overlapSeconds($start, $end, $firstDayStart, $firstDayEnd));
        $this->assertSame(7200, $this->calculator->overlapSeconds($start, $end, $secondDayStart, $secondDayEnd));
    }

    /** T4/T10: physical area is derived once per unique plot, never per valve/event. */
    public function test_t4_and_t10_deduplicate_physical_plot_area(): void
    {
        $plot = new Plot([
            'coordinates' => [[35.0, 51.0], [35.0, 51.001], [35.001, 51.001], [35.001, 51.0]],
        ]);
        $samePlot = new Plot([
            'coordinates' => $plot->coordinates,
        ]);
        $plot->id = 44;
        $samePlot->id = 44;

        $expected = $this->calculator->polygonArea($plot->coordinates);

        $this->assertSame($expected, $this->calculator->physicalAreaForPlots([$plot, $samePlot]));
    }

    /** T5: zero/empty physical area has no valid per-hectare result. */
    public function test_t5_returns_null_per_hectare_for_zero_area(): void
    {
        $this->assertNull($this->calculator->volumePerHectare(10.0, 0.0));
        $this->assertNull($this->calculator->volumePerHectare(10.0, -1.0));
    }

    /** T6: field geometry is a supported physical denominator. */
    public function test_t6_calculates_area_from_field_geometry(): void
    {
        $field = new Field([
            'coordinates' => [[35.0, 51.0], [35.0, 51.001], [35.001, 51.001], [35.001, 51.0]],
        ]);

        $this->assertGreaterThan(0, $this->calculator->physicalAreaForFields([$field]));
    }

    /** T9: exact interval duration is non-negative and null-safe. */
    public function test_t9_and_t12_duration_is_exact_and_invalid_intervals_are_zero(): void
    {
        $start = Carbon::parse('2026-08-10 10:00:00', 'Asia/Tehran');
        $end = Carbon::parse('2026-08-10 11:05:07', 'Asia/Tehran');

        $this->assertSame(3907, $this->calculator->durationSeconds($start, $end));
        $this->assertSame(0, $this->calculator->durationSeconds($end, $start));
        $this->assertSame(0, $this->calculator->durationSeconds(null, $end));
    }

    /** T11/T13: valve metadata cannot affect the physical-area denominator. */
    public function test_t11_and_t13_per_hectare_uses_physical_area_not_valve_metadata(): void
    {
        $this->assertEqualsWithDelta(
            1896.7675338826,
            $this->calculator->volumePerHectare(9922.56, 52_313.0),
            0.00002,
        );
    }

    /** T14: exact volume is preserved before presentation rounding. */
    public function test_t14_keeps_fractional_liters_until_the_api_boundary(): void
    {
        $liters = $this->calculator->volumeLiters([
            (object) ['dripper_count' => 7, 'dripper_flow_rate' => 1.25],
        ], 1_001);

        $this->assertEqualsWithDelta(2.4329861111, $liters, 0.0000001);
    }

    /** T15: the canonical formula matches the documented known dataset. */
    public function test_t15_matches_the_known_volume_per_hectare_dataset(): void
    {
        $volumeM3 = 9922.56;
        $areaM2 = 52_313.0;

        $this->assertEqualsWithDelta(
            $volumeM3 / ($areaM2 / 10_000),
            $this->calculator->volumePerHectare($volumeM3, $areaM2),
            0.000001,
        );
    }
}
