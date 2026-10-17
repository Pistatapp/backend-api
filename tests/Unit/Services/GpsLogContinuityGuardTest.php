<?php

namespace Tests\Unit\Services;

use App\Services\GpsLogContinuityGuard;
use Tests\TestCase;

class GpsLogContinuityGuardTest extends TestCase
{
    private GpsLogContinuityGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new GpsLogContinuityGuard();
        config([
            'trajectory.continuity.max_distance_meters' => 1000.0,
            'trajectory.continuity.max_relocation_meters' => 5000.0,
            'trajectory.continuity.max_plausible_speed_kmh' => 45.0,
        ]);
    }

    public function test_three_kilometre_jump_in_two_hundred_seconds_is_rejected(): void
    {
        $this->assertTrue($this->guard->isOutlier(35.0, 51.0, 0, 35.027, 51.0, 200));
    }

    public function test_long_quiet_period_can_keep_a_small_relocation_for_segmenting(): void
    {
        $this->assertFalse($this->guard->isOutlier(35.0, 51.0, 0, 35.010, 51.0, 1800));
    }

    public function test_large_relocation_is_rejected_even_after_a_long_gap(): void
    {
        $this->assertTrue($this->guard->isOutlier(35.0, 51.0, 0, 36.0, 51.0, 7200));
    }
}
