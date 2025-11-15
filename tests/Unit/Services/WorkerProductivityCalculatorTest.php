<?php

namespace Tests\Unit\Services;

use App\Models\WorkerAttendanceSession;
use App\Services\WorkerProductivityCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerProductivityCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private WorkerProductivityCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new WorkerProductivityCalculator();
    }

    /**
     * Test calculate productivity score from session.
     */
    public function test_calculate_productivity_score_from_session(): void
    {
        $session = WorkerAttendanceSession::factory()->create([
            'total_in_zone_duration' => 480, // 8 hours
            'total_out_zone_duration' => 120, // 2 hours
        ]);

        $score = $this->calculator->calculate($session);

        $this->assertIsFloat($score);
        // 480 / (480 + 120) * 100 = 80%
        $this->assertEquals(80.0, $score);
    }

    /**
     * Test calculate returns 100% when all time is in zone.
     */
    public function test_calculate_returns_100_percent_when_all_time_in_zone(): void
    {
        $session = WorkerAttendanceSession::factory()->create([
            'total_in_zone_duration' => 480, // 8 hours
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
        $session = WorkerAttendanceSession::factory()->create([
            'total_in_zone_duration' => 0,
            'total_out_zone_duration' => 480, // 8 hours
        ]);

        $score = $this->calculator->calculate($session);

        $this->assertEquals(0.0, $score);
    }

    /**
     * Test calculate returns null when no attendance time.
     */
    public function test_calculate_returns_null_when_no_attendance_time(): void
    {
        $session = WorkerAttendanceSession::factory()->create([
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
        $session = WorkerAttendanceSession::factory()->create([
            'total_in_zone_duration' => 333, // 5.55 hours
            'total_out_zone_duration' => 167, // 2.78 hours
        ]);

        $score = $this->calculator->calculate($session);

        // 333 / 500 * 100 = 66.6%
        $this->assertEquals(66.6, $score);
    }

    /**
     * Test calculate from times directly.
     */
    public function test_calculate_from_times_directly(): void
    {
        $score = $this->calculator->calculateFromTimes(400, 100);

        $this->assertIsFloat($score);
        // 400 / 500 * 100 = 80%
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
        $session = WorkerAttendanceSession::factory()->create([
            'total_in_zone_duration' => 1,
            'total_out_zone_duration' => 3,
        ]);

        $score = $this->calculator->calculate($session);

        // 1 / 4 * 100 = 25%
        $this->assertEquals(25.0, $score);
    }
}

