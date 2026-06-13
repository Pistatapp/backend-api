<?php

namespace Tests\Unit\Helpers;

use Tests\TestCase;

class IrrigationVolumeCalculationTest extends TestCase
{
    /**
     * Test volume per hectare uses total volume divided by summed irrigation areas.
     */
    public function test_calculate_irrigation_volume_per_hectare_uses_total_volume_over_total_area(): void
    {
        $durationInSeconds = 7200; // 2 hours

        $valves = [
            (object) [
                'dripper_count' => 500,
                'dripper_flow_rate' => 4.5,
                'irrigation_area' => 2.0,
            ],
            (object) [
                'dripper_count' => 300,
                'dripper_flow_rate' => 4.0,
                'irrigation_area' => 1.0,
            ],
        ];

        // Valve 1: 500 * 4.5 * 2 = 4500 L
        // Valve 2: 300 * 4.0 * 2 = 2400 L
        // Total: 6900 L over 3.0 ha => 2300 L/ha => 2.3 m³/ha
        $this->assertEqualsWithDelta(
            2.3,
            calculate_irrigation_volume_per_hectare($valves, $durationInSeconds),
            0.0001
        );
    }

    /**
     * Test volume per hectare returns zero when total irrigation area is zero.
     */
    public function test_calculate_irrigation_volume_per_hectare_returns_zero_without_area(): void
    {
        $valves = [
            (object) [
                'dripper_count' => 100,
                'dripper_flow_rate' => 2.0,
                'irrigation_area' => 0,
            ],
        ];

        $this->assertSame(0.0, calculate_irrigation_volume_per_hectare($valves, 3600));
    }
}
