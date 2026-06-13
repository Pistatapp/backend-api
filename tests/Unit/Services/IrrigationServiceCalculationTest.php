<?php

namespace Tests\Unit\Services;

use App\Services\IrrigationService;
use Tests\TestCase;

class IrrigationServiceCalculationTest extends TestCase
{
    private IrrigationService $irrigationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->irrigationService = app(IrrigationService::class);
    }

    /**
     * Test volume per hectare uses total volume divided by summed irrigation areas.
     */
    public function test_calculate_volume_per_hectare_uses_total_volume_over_total_area(): void
    {
        $durationInSeconds = 7200; // 2 hours

        $valves = [
            (object) [
                'dripper_count' => 500,
                'dripper_flow_rate' => 4.5,
                'irrigation_area' => 2,
            ],
            (object) [
                'dripper_count' => 300,
                'dripper_flow_rate' => 4.0,
                'irrigation_area' => 1,
            ],
        ];

        // Valve 1: 500 * 4.5 * 2 = 4500 L
        // Valve 2: 300 * 4.0 * 2 = 2400 L
        // Total: 6900 L over 3.0 ha => 2.3 m³/ha
        $this->assertEqualsWithDelta(
            2.3,
            $this->irrigationService->calculateVolumePerHectare($valves, $durationInSeconds),
            0.0001
        );
    }

    /**
     * Test volume per hectare scales with total volume when area is unchanged.
     */
    public function test_calculate_volume_per_hectare_scales_with_total_volume(): void
    {
        $valves = [
            (object) [
                'dripper_count' => 500,
                'dripper_flow_rate' => 4.5,
                'irrigation_area' => 2.5,
            ],
        ];

        $oneHourPerHectare = $this->irrigationService->calculateVolumePerHectare($valves, 3600);
        $twoHourPerHectare = $this->irrigationService->calculateVolumePerHectare($valves, 7200);

        $this->assertEqualsWithDelta(0.9, $oneHourPerHectare, 0.0001);
        $this->assertEqualsWithDelta(1.8, $twoHourPerHectare, 0.0001);
        $this->assertNotEquals($oneHourPerHectare, $twoHourPerHectare);
    }

    /**
     * Test volume per hectare returns zero when total irrigation area is zero.
     */
    public function test_calculate_volume_per_hectare_returns_zero_without_area(): void
    {
        $valves = [
            (object) [
                'dripper_count' => 100,
                'dripper_flow_rate' => 2.0,
                'irrigation_area' => 0,
            ],
        ];

        $this->assertSame(0.0, $this->irrigationService->calculateVolumePerHectare($valves, 3600));
    }
}
