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

    /** T1/T2/T6/T7/T11/T14: selected plot and valve scope is intersected. */
    public function test_selected_plot_report_excludes_off_scope_valves_and_preserves_period_consistency(): void
    {
        [$farm, $field, $plotOne, $plotTwo] = $this->makeScope();
        $inScopeValve = $this->makeValve($plotOne, 100, 1);
        $offScopeValve = $this->makeValve($plotTwo, 900, 1);
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
        $this->assertEqualsWithDelta(
            $report['accumulated']['total_volume_per_hectare'],
            collect($report['irrigations'])->sum('total_volume_per_hectare'),
            0.00001,
        );
    }

    /** T4/T5: whole-field selection uses the field polygon exactly once. */
    public function test_whole_field_selection_uses_field_area_once_even_when_multiple_plots_are_attached(): void
    {
        [$farm, $field, $plotOne, $plotTwo] = $this->makeScope();
        $valveOne = $this->makeValve($plotOne, 100, 1);
        $valveTwo = $this->makeValve($plotTwo, 100, 1);
        $irrigation = $this->makeIrrigation($farm, $plotOne, $valveOne, '2026-08-10 10:00:00', '2026-08-10 11:00:00');
        $irrigation->plots()->attach($plotTwo->id);
        $irrigation->valves()->attach($valveTwo->id);

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'field_ids' => [$field->id],
            'from_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $expectedArea = app(IrrigationReportCalculationService::class)->polygonArea($field->coordinates);
        $this->assertSame('field_polygon', $report['accumulated']['area_source']);
        $this->assertEqualsWithDelta($expectedArea, $report['accumulated']['physical_area_m2'], 0.00001);
        $this->assertEqualsWithDelta(0.2, $report['accumulated']['total_volume'], 0.00001);
    }

    /** T9/T10: a 48-hour program is split into 14 + 24 + 10 hours and clipped. */
    public function test_multi_day_program_is_split_by_daily_overlap_and_clipped_to_requested_range(): void
    {
        [$farm, , $plot] = $this->makeScope();
        $valve = $this->makeValve($plot, 100, 1);
        $this->makeIrrigation($farm, $plot, $valve, '2026-08-01 10:00:00', '2026-08-03 10:00:00');

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [$plot->id],
            'from_date' => Carbon::parse('2026-08-01', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-03', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $durations = collect($report['irrigations'])->pluck('total_duration')->all();
        $this->assertSame(['14:00:00', '24:00:00', '10:00:00'], $durations);
        $this->assertSame('48:00:00', $report['accumulated']['total_duration']);
    }

    /** T12/T13: no geometry and no records produce safe, explicit results. */
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

    /** T3: selected plot geometry is summed once, independently of event count. */
    public function test_selected_plots_use_unique_plot_geometry_for_the_denominator(): void
    {
        [$farm, , $plotOne, $plotTwo] = $this->makeScope();
        $valveOne = $this->makeValve($plotOne, 100, 1);
        $valveTwo = $this->makeValve($plotTwo, 100, 1);
        $this->makeIrrigation($farm, $plotOne, $valveOne, '2026-08-10 10:00:00', '2026-08-10 11:00:00');
        $second = $this->makeIrrigation($farm, $plotOne, $valveOne, '2026-08-11 10:00:00', '2026-08-11 11:00:00');
        $second->plots()->attach($plotTwo->id);
        $second->valves()->attach($valveTwo->id);

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [$plotOne->id, $plotTwo->id],
            'from_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-11', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $calculator = app(IrrigationReportCalculationService::class);
        $expectedArea = $calculator->polygonArea($plotOne->coordinates)
            + $calculator->polygonArea($plotTwo->coordinates);
        $this->assertEqualsWithDelta($expectedArea, $report['accumulated']['physical_area_m2'], 0.00001);
    }

    /** Legacy Android clients send `valves`; the corrected service must retain that contract. */
    public function test_legacy_valves_request_key_remains_supported(): void
    {
        [$farm, , $plot] = $this->makeScope();
        $valve = $this->makeValve($plot, 100, 1);
        $this->makeIrrigation($farm, $plot, $valve, '2026-08-10 10:00:00', '2026-08-10 11:00:00');

        $report = app(IrrigationReportService::class)->getAggregatedReports($farm, [
            'plot_ids' => [$plot->id],
            'valves' => [$valve->id],
            'from_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
            'to_date' => Carbon::parse('2026-08-10', IrrigationReportCalculationService::TIMEZONE),
        ]);

        $this->assertSame(1, $report['accumulated']['total_count']);
        $this->assertEqualsWithDelta(0.1, $report['accumulated']['total_volume'], 0.00001);
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

    private function makeValve(Plot $plot, int $dripperCount, float $flowRate): Valve
    {
        return Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => $dripperCount,
            'dripper_flow_rate' => $flowRate,
            'irrigation_area' => 0.01,
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
