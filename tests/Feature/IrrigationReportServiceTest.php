<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Irrigation;
use App\Models\Plot;
use App\Models\Valve;
use App\Services\IrrigationReportCalculationService;
use App\Services\IrrigationReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IrrigationReportServiceTest extends TestCase
{
    use RefreshDatabase;

    /** T1: single program / single kart → volume / area. */
    public function test_t1_single_program_single_kart_m3_per_ha(): void
    {
        [$farm, , $plot] = $this->makeScope();
        // 100 drippers × 1 L/h × 1 h = 100 L = 0.1 m³ over 0.5 ha → 0.2 m³/ha
        // Scale: 1000 drippers × 100 L/h × 1 h = 100_000 L = 100 m³ / 0.5 ha = 200
        $valve = $this->makeValve($plot, 1000, 100, 0.5);
        $this->makeIrrigation($farm, $plot, $valve, '2026-08-10 10:00:00', '2026-08-10 11:00:00');

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [$plot->id],
            'from_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $this->assertCount(1, $report['irrigations']);
        $this->assertEqualsWithDelta(100.0, $report['irrigations'][0]['total_volume'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $report['irrigations'][0]['irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(200.0, $report['irrigations'][0]['total_volume_per_hectare'], 0.0001);
        $this->assertEqualsWithDelta(200.0, $report['accumulated']['total_volume_per_hectare'], 0.0001);
    }

    /** T2: single program / multiple karts → sum unique kart areas. */
    public function test_t2_single_program_multiple_karts_m3_per_ha(): void
    {
        [$farm, $field, $plotOne, $plotTwo] = $this->makeScope();
        $plotThree = Plot::factory()->create([
            'field_id' => $field->id,
            'coordinates' => [[35.001, 51.0], [35.001, 51.0004], [35.0014, 51.0004], [35.0014, 51.0]],
        ]);

        // Build volumes that sum to 552 m³ over 1.6 ha in 1 hour:
        // V1: 0.5 ha → need 552 * 0.5/1.6 = 172.5 m³ → 172500 L/h → dripper_count*flow = 172500
        // Use one shared duration and absolute dripper×flow to hit exact 552.
        $valveOne = $this->makeValve($plotOne, 172500, 1, 0.5);
        $valveTwo = $this->makeValve($plotTwo, 138000, 1, 0.4);
        $valveThree = $this->makeValve($plotThree, 241500, 1, 0.7);

        $irrigation = $this->makeIrrigation($farm, $plotOne, $valveOne, '2026-08-10 10:00:00', '2026-08-10 11:00:00');
        $irrigation->plots()->attach([$plotTwo->id, $plotThree->id]);
        $irrigation->valves()->attach([$valveTwo->id, $valveThree->id]);

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [$plotOne->id, $plotTwo->id, $plotThree->id],
            'from_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $this->assertEqualsWithDelta(552.0, $report['accumulated']['total_volume'], 0.0001);
        $this->assertEqualsWithDelta(1.6, $report['accumulated']['total_irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(345.0, $report['accumulated']['total_volume_per_hectare'], 0.0001);
    }

    /** T3: multi-day period footer is volume/area sum, never sum of daily m³/ha. */
    public function test_t3_period_footer_is_not_sum_of_daily_intensities(): void
    {
        [$farm, $field, $plotA, $plotB] = $this->makeScope();
        $plotC = Plot::factory()->create([
            'field_id' => $field->id,
            'coordinates' => [[35.001, 51.0], [35.001, 51.0004], [35.0014, 51.0004], [35.0014, 51.0]],
        ]);

        // Day1: 200 m³ / 1.0 ha
        $vA = $this->makeValve($plotA, 200000, 1, 1.0);
        $this->makeIrrigation($farm, $plotA, $vA, '2026-08-10 10:00:00', '2026-08-10 11:00:00');

        // Day2: 160 m³ / 0.8 ha
        $vB = $this->makeValve($plotB, 160000, 1, 0.8);
        $this->makeIrrigation($farm, $plotB, $vB, '2026-08-11 10:00:00', '2026-08-11 11:00:00');

        // Day3: 150 m³ / 0.5 ha
        $vC = $this->makeValve($plotC, 150000, 1, 0.5);
        $this->makeIrrigation($farm, $plotC, $vC, '2026-08-12 10:00:00', '2026-08-12 11:00:00');

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [$plotA->id, $plotB->id, $plotC->id],
            'from_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-12', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $this->assertCount(3, $report['irrigations']);
        $this->assertEqualsWithDelta(200.0, $report['irrigations'][0]['total_volume_per_hectare'], 0.0001);
        $this->assertEqualsWithDelta(200.0, $report['irrigations'][1]['total_volume_per_hectare'], 0.0001);
        $this->assertEqualsWithDelta(300.0, $report['irrigations'][2]['total_volume_per_hectare'], 0.0001);

        $this->assertEqualsWithDelta(510.0, $report['accumulated']['total_volume'], 0.0001);
        $this->assertEqualsWithDelta(2.3, $report['accumulated']['total_irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(221.7391304348, $report['accumulated']['total_volume_per_hectare'], 0.00001);

        $sumDaily = collect($report['irrigations'])->sum('total_volume_per_hectare');
        $this->assertEqualsWithDelta(700.0, $sumDaily, 0.0001);
        $this->assertNotEqualsWithDelta(
            $sumDaily,
            $report['accumulated']['total_volume_per_hectare'],
            0.001
        );
    }

    /** T4: duplicate plot/valve references count area once per program. */
    public function test_t4_duplicate_plot_area_counted_once_per_program(): void
    {
        [$farm, , $plot] = $this->makeScope();
        $valveOne = $this->makeValve($plot, 50000, 1, 0.5);
        $valveTwo = $this->makeValve($plot, 50000, 1, 0.5);
        $irrigation = $this->makeIrrigation($farm, $plot, $valveOne, '2026-08-10 10:00:00', '2026-08-10 11:00:00');
        $irrigation->valves()->attach($valveTwo->id);

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [$plot->id],
            'from_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
        ]);

        // Volume = (50000+50000)*1h/1000 = 100 m³; selected valve areas = 1.0 ha → 100 m³/ha
        $this->assertEqualsWithDelta(100.0, $report['accumulated']['total_volume'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $report['accumulated']['total_irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $report['accumulated']['total_volume_per_hectare'], 0.0001);
    }

    /** T5: zero irrigated area returns null m³/ha. */
    public function test_t5_zero_area_returns_null_m3_per_ha(): void
    {
        [$farm, , $plot] = $this->makeScope();
        $valve = $this->makeValve($plot, 100, 1, 0.0);
        $this->makeIrrigation($farm, $plot, $valve, '2026-08-10 10:00:00', '2026-08-10 11:00:00');

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [$plot->id],
            'from_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $this->assertNull($report['irrigations'][0]['total_volume_per_hectare']);
        $this->assertNull($report['accumulated']['total_volume_per_hectare']);
    }

    /** T6: legacy Android `valves` request key remains supported. */
    public function test_t6_legacy_valves_request_key_remains_supported(): void
    {
        [$farm, , $plot] = $this->makeScope();
        $valve = $this->makeValve($plot, 100, 1, 0.01);
        $this->makeIrrigation($farm, $plot, $valve, '2026-08-10 10:00:00', '2026-08-10 11:00:00');

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [$plot->id],
            'valves' => [$valve->id],
            'from_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $this->assertSame(1, $report['accumulated']['total_count']);
        $this->assertEqualsWithDelta(0.1, $report['accumulated']['total_volume'], 0.00001);
        $this->assertArrayHasKey('total_volume_per_hectare', $report['accumulated']);
        $this->assertArrayHasKey('total_volume', $report['accumulated']);
        $this->assertArrayHasKey('total_duration', $report['accumulated']);
        $this->assertArrayHasKey('total_count', $report['accumulated']);
    }

    /** D1: three simultaneous Karts contribute two elapsed hours, not six. */
    public function test_d1_three_simultaneous_karts_use_union_duration(): void
    {
        [$farm, $field, $plotOne, $plotTwo] = $this->makeScope();
        $plotThree = Plot::factory()->create(['field_id' => $field->id]);

        $this->makeIrrigation($farm, $plotOne, $this->makeValve($plotOne, 1000, 1, 0.5), '2026-08-10 10:00:00', '2026-08-10 12:00:00');
        $this->makeIrrigation($farm, $plotTwo, $this->makeValve($plotTwo, 1000, 1, 0.4), '2026-08-10 10:00:00', '2026-08-10 12:00:00');
        $this->makeIrrigation($farm, $plotThree, $this->makeValve($plotThree, 1000, 1, 0.7), '2026-08-10 10:00:00', '2026-08-10 12:00:00');

        $report = $this->report($farm, ['field_ids' => [$field->id]], '2026-08-10', '2026-08-10');

        $this->assertSame('02:00:00', $report['irrigations'][0]['total_duration']);
        $this->assertSame('02:00:00', $report['accumulated']['total_duration']);
        $this->assertEqualsWithDelta(6.0, $report['accumulated']['total_volume'], 0.0001);
    }

    /** D2: a single Plot keeps its own elapsed interval. */
    public function test_d2_single_plot_duration_is_not_affected_by_other_plots(): void
    {
        [$farm, , $plot] = $this->makeScope();
        $this->makeIrrigation($farm, $plot, $this->makeValve($plot, 1000, 1, 0.5), '2026-08-10 10:00:00', '2026-08-10 12:00:00');

        $report = $this->report($farm, ['plot_ids' => [$plot->id]], '2026-08-10', '2026-08-10');

        $this->assertSame('02:00:00', $report['accumulated']['total_duration']);
    }

    /** D3: partially overlapping intervals merge into one three-hour period. */
    public function test_d3_partial_overlap_uses_union_duration(): void
    {
        [$farm, $field, $plotOne, $plotTwo] = $this->makeScope();
        $this->makeIrrigation($farm, $plotOne, $this->makeValve($plotOne, 1000, 1, 0.5), '2026-08-10 10:00:00', '2026-08-10 12:00:00');
        $this->makeIrrigation($farm, $plotTwo, $this->makeValve($plotTwo, 1000, 1, 0.4), '2026-08-10 11:00:00', '2026-08-10 13:00:00');

        $report = $this->report($farm, ['field_ids' => [$field->id]], '2026-08-10', '2026-08-10');

        $this->assertSame('03:00:00', $report['accumulated']['total_duration']);
    }

    /** D4: non-overlapping intervals remain additive. */
    public function test_d4_non_overlapping_intervals_add_duration(): void
    {
        [$farm, $field, $plotOne, $plotTwo] = $this->makeScope();
        $this->makeIrrigation($farm, $plotOne, $this->makeValve($plotOne, 1000, 1, 0.5), '2026-08-10 08:00:00', '2026-08-10 10:00:00');
        $this->makeIrrigation($farm, $plotTwo, $this->makeValve($plotTwo, 1000, 1, 0.4), '2026-08-10 14:00:00', '2026-08-10 16:00:00');

        $report = $this->report($farm, ['field_ids' => [$field->id]], '2026-08-10', '2026-08-10');

        $this->assertSame('04:00:00', $report['accumulated']['total_duration']);
    }

    /** D5: duplicate records for one interval do not duplicate elapsed time. */
    public function test_d5_duplicate_intervals_count_once_for_duration(): void
    {
        [$farm, , $plot] = $this->makeScope();
        $valve = $this->makeValve($plot, 1000, 1, 0.5);
        $this->makeIrrigation($farm, $plot, $valve, '2026-08-10 10:00:00', '2026-08-10 12:00:00');
        $this->makeIrrigation($farm, $plot, $valve, '2026-08-10 10:00:00', '2026-08-10 12:00:00');

        $report = $this->report($farm, ['plot_ids' => [$plot->id]], '2026-08-10', '2026-08-10');

        $this->assertSame('02:00:00', $report['accumulated']['total_duration']);
    }

    /** D6: a cross-midnight interval is clipped per local Tehran calendar day. */
    public function test_d6_cross_midnight_duration_is_split_by_day(): void
    {
        [$farm, , $plot] = $this->makeScope();
        $this->makeIrrigation($farm, $plot, $this->makeValve($plot, 1000, 1, 0.5), '2026-08-10 22:00:00', '2026-08-11 02:00:00');

        $report = $this->report($farm, ['plot_ids' => [$plot->id]], '2026-08-10', '2026-08-11');

        $this->assertSame(['02:00:00', '02:00:00'], collect($report['irrigations'])->pluck('total_duration')->all());
        $this->assertSame('04:00:00', $report['accumulated']['total_duration']);
    }

    /** I2: daily intensity aggregates volumes and area occurrences first. */
    public function test_i2_daily_intensity_uses_daily_volume_over_area_occurrences(): void
    {
        [$farm, , $plotOne, $plotTwo] = $this->makeScope();
        $this->makeIrrigation($farm, $plotOne, $this->makeValve($plotOne, 100000, 1, 0.5), '2026-08-10 10:00:00', '2026-08-10 11:00:00');
        $this->makeIrrigation($farm, $plotTwo, $this->makeValve($plotTwo, 150000, 1, 0.5), '2026-08-10 12:00:00', '2026-08-10 13:00:00');

        $report = $this->report($farm, ['plot_ids' => [$plotOne->id, $plotTwo->id]], '2026-08-10', '2026-08-10');

        $this->assertEqualsWithDelta(250.0, $report['irrigations'][0]['total_volume'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $report['irrigations'][0]['irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(250.0, $report['irrigations'][0]['total_volume_per_hectare'], 0.0001);
    }

    /** I3: the same Kart on two days contributes two hectare-occurrences. */
    public function test_i3_same_kart_on_two_days_counts_two_area_occurrences(): void
    {
        [$farm, , $plot] = $this->makeScope();
        $this->makeIrrigation($farm, $plot, $this->makeValve($plot, 100000, 1, 0.5), '2026-08-10 10:00:00', '2026-08-10 11:00:00');
        $this->makeIrrigation($farm, $plot, $this->makeValve($plot, 150000, 1, 0.5), '2026-08-11 10:00:00', '2026-08-11 11:00:00');

        $report = $this->report($farm, ['plot_ids' => [$plot->id]], '2026-08-10', '2026-08-11');

        $this->assertEqualsWithDelta(250.0, $report['accumulated']['total_volume'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $report['accumulated']['total_irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(250.0, $report['accumulated']['total_volume_per_hectare'], 0.0001);
    }

    /** I5: field polygon metadata is not the irrigated-area denominator. */
    public function test_i5_physical_field_area_is_not_used_for_intensity(): void
    {
        [$farm, $field, $plot] = $this->makeScope();
        $field->update([
            'coordinates' => [[35.0, 51.0], [35.0, 51.01], [35.01, 51.01], [35.01, 51.0]],
        ]);
        $this->makeIrrigation($farm, $plot, $this->makeValve($plot, 510000, 1, 2.3), '2026-08-10 10:00:00', '2026-08-10 11:00:00');

        $report = $this->report($farm, ['field_ids' => [$field->id]], '2026-08-10', '2026-08-10');

        $this->assertNotEqualsWithDelta(2.3, $report['accumulated']['physical_area_ha'], 0.0001);
        $this->assertSame('valve.irrigation_area', $report['accumulated']['area_source']);
        $this->assertEqualsWithDelta(510.0, $report['accumulated']['total_volume'], 0.0001);
        $this->assertEqualsWithDelta(2.3, $report['accumulated']['total_irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(510 / 2.3, $report['accumulated']['total_volume_per_hectare'], 0.0001);
    }

    /** Off-scope valves stay excluded; period volume equals sum of daily volumes. */
    public function test_selected_plot_report_excludes_off_scope_valves(): void
    {
        [$farm, $field, $plotOne, $plotTwo] = $this->makeScope();
        $inScopeValve = $this->makeValve($plotOne, 100, 1, 0.01);
        $offScopeValve = $this->makeValve($plotTwo, 900, 1, 0.01);
        $irrigation = $this->makeIrrigation($farm, $plotOne, $inScopeValve, '2026-08-10 22:00:00', '2026-08-11 02:00:00');
        $irrigation->valves()->attach($offScopeValve->id);

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'field_ids' => [$field->id],
            'plot_ids' => [$plotOne->id],
            'valve_ids' => [$inScopeValve->id],
            'from_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-11', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $this->assertCount(2, $report['irrigations']);
        $this->assertEqualsWithDelta(0.4, $report['accumulated']['total_volume'], 0.00001);
        $this->assertSame(1, $report['accumulated']['total_count']);
        $this->assertEqualsWithDelta(
            $report['accumulated']['total_volume'],
            collect($report['irrigations'])->sum('total_volume'),
            0.00001,
        );
        // The total denominator is the selected valve area once: 0.4 / 0.01 = 40 m³/ha.
        $this->assertEqualsWithDelta(40.0, $report['accumulated']['total_volume_per_hectare'], 0.00001);
    }

    /** Multi-day programs split detail rows; total denominator counts each selected valve once. */
    public function test_multi_day_program_splits_volume_and_repeats_area_per_day(): void
    {
        [$farm, , $plot] = $this->makeScope();
        $valve = $this->makeValve($plot, 100, 1, 1.6);
        $this->makeIrrigation($farm, $plot, $valve, '2026-08-01 10:00:00', '2026-08-03 10:00:00');

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [$plot->id],
            'from_date' => Carbon::parse('2026-08-01', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-03', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $durations = collect($report['irrigations'])->pluck('total_duration')->all();
        $this->assertSame(['14:00:00', '24:00:00', '10:00:00'], $durations);
        $this->assertSame('48:00:00', $report['accumulated']['total_duration']);
        $this->assertEqualsWithDelta(1.6, $report['irrigations'][0]['irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(1.6, $report['irrigations'][1]['irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(1.6, $report['irrigations'][2]['irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(1.6, $report['accumulated']['total_irrigated_area_ha'], 0.0001);
        $this->assertEqualsWithDelta(4.8 / 1.6, $report['accumulated']['total_volume_per_hectare'], 0.0001);
    }

    public function test_empty_scope_returns_an_empty_safe_response(): void
    {
        $farm = Farm::factory()->create();

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [],
            'from_date' => Carbon::parse('2026-08-01', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-01', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $this->assertSame([], $report['irrigations']);
        $this->assertNull($report['accumulated']['total_volume_per_hectare']);
        $this->assertSame(0, $report['accumulated']['total_count']);
    }

    private function report(Farm $farm, array $scope, string $fromDate, string $toDate): array
    {
        return app(IrrigationReportService::class)->getAggregatedReports($farm, array_merge($scope, [
            'from_date' => Carbon::parse($fromDate, IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse($toDate, IrrigationReportCalculationService::TIMEZONE),
        ]));
    }

    /** @return array{0: Farm, 1: Field, 2: Plot, 3: Plot} */
    private function makeScope(): array
    {
        $farm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'coordinates' => [[35.0, 51.0], [35.0, 51.001], [35.001, 51.001], [35.001, 51.0]],
        ]);
        $plotOne = Plot::factory()->create([
            'field_id' => $field->id,
            'coordinates' => [[35.0, 51.0], [35.0, 51.0004], [35.0004, 51.0004], [35.0004, 51.0]],
        ]);
        $plotTwo = Plot::factory()->create([
            'field_id' => $field->id,
            'coordinates' => [[35.0005, 51.0], [35.0005, 51.0004], [35.0009, 51.0004], [35.0009, 51.0]],
        ]);

        return [$farm, $field, $plotOne, $plotTwo];
    }

    private function makeValve(Plot $plot, int $dripperCount, float $flowRate, float $irrigationAreaHa): Valve
    {
        return Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => $dripperCount,
            'dripper_flow_rate' => $flowRate,
            'irrigation_area' => $irrigationAreaHa,
        ]);
    }

    private function makeIrrigation(
        Farm $farm,
        Plot $plot,
        Valve $valve,
        string $start,
        string $end,
    ): Irrigation {
        $irrigation = Irrigation::factory()->create([
            'farm_id' => $farm->id,
            'start_time' => Carbon::parse($start, IrrigationReportCalculationService::TIMEZONE),
            'end_time' => Carbon::parse($end, IrrigationReportCalculationService::TIMEZONE),
            'status' => 'finished',
            'is_verified_by_admin' => true,
        ]);
        $irrigation->plots()->attach($plot->id);
        $irrigation->valves()->attach($valve->id);

        return $irrigation;
    }
}
