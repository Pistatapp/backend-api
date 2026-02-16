<?php

namespace Tests\Unit\Services;

use App\Models\AttendanceSession;
use App\Services\AttendanceProductivityCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceProductivityCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceProductivityCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new AttendanceProductivityCalculator();
    }

    /**
     * Test calculate productivity score from session.
     */
    public function test_calculate_productivity_score_from_session(): void
    {
        $session = AttendanceSession::factory()->create([
            'total_in_zone_duration' => 480,
            'total_out_zone_duration' => 120,
        ]);

        $score = $this->calculator->calculate($session);

        $this->assertIsFloat($score);
        $this->assertEquals(80.0, $score);
    }

    /**
     * Test calculate returns 100% when all time is in zone.
     */
    public function test_calculate_returns_100_percent_when_all_time_in_zone(): void
    {
        $session = AttendanceSession::factory()->create([
            'total_in_zone_duration' => 480,
            'total_out_zone_duration' => 0,
        ]);

        $score = $this->calculator->calculate($session);

        $this->assertEquals(100.0, $score);
    }

    /**
     * Test calculate returns 0% when all time is out of zone.
     */
    public function test_calculate_returns_0_percent_when_all_time_out_of_zone(): void
    {
        $session = AttendanceSession::factory()->create([
            'total_in_zone_duration' => 0,
            'total_out_zone_duration' => 480,
        ]);

        $score = $this->calculator->calculate($session);

        $this->assertEquals(0.0, $score);
    }

    /**
     * Test calculate returns null when no attendance time.
     */
    public function test_calculate_returns_null_when_no_attendance_time(): void
    {
        $session = AttendanceSession::factory()->create([
            'total_in_zone_duration' => 0,
            'total_out_zone_duration' => 0,
        ]);

        $score = $this->calculator->calculate($session);

        $this->assertNull($score);
    }

    /**
     * Test calculate rounds to 2 decimal places.
     */
    public function test_calculate_rounds_to_2_decimal_places(): void
    {
        $session = AttendanceSession::factory()->create([
            'total_in_zone_duration' => 333,
            'total_out_zone_duration' => 167,
        ]);

        $score = $this->calculator->calculate($session);

        $this->assertEquals(66.6, $score);
    }

    /**
     * Test calculate from times directly.
     */
    public function test_calculate_from_times_directly(): void
    {
        $score = $this->calculator->calculateFromTimes(400, 100);

        $this->assertIsFloat($score);
        $this->assertEquals(80.0, $score);
    }

    /**
     * Test calculate from times returns null when no time.
     */
    public function test_calculate_from_times_returns_null_when_no_time(): void
    {
        $score = $this->calculator->calculateFromTimes(0, 0);

        $this->assertNull($score);
    }

    /**
     * Test calculate handles fractional percentages correctly.
     */
    public function test_calculate_handles_fractional_percentages_correctly(): void
    {
        $session = AttendanceSession::factory()->create([
            'total_in_zone_duration' => 1,
            'total_out_zone_duration' => 3,
        ]);

        $score = $this->calculator->calculate($session);

        $this->assertEquals(25.0, $score);
    }
}
