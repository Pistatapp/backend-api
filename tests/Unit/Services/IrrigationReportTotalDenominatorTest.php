<?php

namespace Tests\Unit\Services;

use App\Models\Valve;
use App\Services\IrrigationReportCalculationService;
use Tests\TestCase;

class IrrigationReportTotalDenominatorTest extends TestCase
{
    public function test_total_denominator_sums_unique_selected_valves(): void
    {
        $calculator = new IrrigationReportCalculationService();
        $a = new Valve(['irrigation_area' => 1.00]);
        $a->id = 77;
        $b = new Valve(['irrigation_area' => 1.45]);
        $b->id = 78;
        $c = new Valve(['irrigation_area' => 1.45]);
        $c->id = 79;

        $this->assertEqualsWithDelta(3.90, $calculator->selectedValveAreaHectares([$a, $b, $c]), 0.000001);
        $this->assertEqualsWithDelta(200.0, $calculator->volumePerHectareFromHa(780.0, 3.90), 0.000001);
    }

    public function test_field_nine_period_example_uses_selected_valve_area_once(): void
    {
        $calculator = new IrrigationReportCalculationService();
        $areas = [1.00, 1.00, 1.45, 1.45];
        $valves = collect($areas)->values()->map(function (float $area, int $index): Valve {
            $valve = new Valve(['irrigation_area' => $area]);
            $valve->id = 77 + $index;

            return $valve;
        });

        $denominator = $calculator->selectedValveAreaHectares($valves);

        $this->assertEqualsWithDelta(4.90, $denominator, 0.000001);
        $this->assertEqualsWithDelta(979.2, $calculator->volumePerHectareFromHa(4798.08, $denominator), 0.000001);
    }

    public function test_repeated_events_do_not_repeat_a_valves_area(): void
    {
        $calculator = new IrrigationReportCalculationService();
        $valve = new Valve(['irrigation_area' => 1.25]);
        $valve->id = 77;

        $this->assertEqualsWithDelta(1.25, $calculator->selectedValveAreaHectares([$valve, $valve]), 0.000001);
    }

    public function test_invalid_areas_are_not_replaced_with_gis_area(): void
    {
        $calculator = new IrrigationReportCalculationService();
        $zero = new Valve(['irrigation_area' => 0]);
        $zero->id = 77;
        $negative = new Valve(['irrigation_area' => -1]);
        $negative->id = 78;

        $this->assertEqualsWithDelta(0.0, $calculator->selectedValveAreaHectares([$zero, $negative]), 0.000001);
        $this->assertNull($calculator->volumePerHectareFromHa(780.0, 0.0));
    }
}
