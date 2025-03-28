<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\KalmanFilter;
use PHPUnit\Framework\Attributes\Test;

class KalmanFilterTest extends TestCase
{
    private KalmanFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new KalmanFilter();
    }

    #[Test]
    public function it_initializes_with_first_coordinate()
    {
        $result = $this->filter->filter(34.884065, 50.599625);

        $this->assertEquals(34.884065, $result['latitude']);
        $this->assertEquals(50.599625, $result['longitude']);
    }

    #[Test]
    public function it_smooths_noisy_coordinates()
    {
        // First point
        $this->filter->filter(34.884065, 50.599625);

        // Slightly noisy second point
        $result = $this->filter->filter(34.884067, 50.599627);

        // Results should be between initial and new coordinates due to smoothing
        $this->assertGreaterThan(34.884065, $result['latitude']);
        $this->assertLessThan(34.884067, $result['latitude']);
        $this->assertGreaterThan(50.599625, $result['longitude']);
        $this->assertLessThan(50.599627, $result['longitude']);
    }

    #[Test]
    public function it_resets_filter_state()
    {
        // Initial point
        $this->filter->filter(34.884065, 50.599625);

        // Reset filter
        $this->filter->reset();

        // New point after reset should be taken as-is
        $result = $this->filter->filter(34.884070, 50.599630);
        $this->assertEquals(34.884070, $result['latitude']);
        $this->assertEquals(50.599630, $result['longitude']);
    }

    #[Test]
    public function it_handles_large_position_jumps()
    {
        // Initial position
        $this->filter->filter(34.884065, 50.599625);

        // Large jump in position
        $result = $this->filter->filter(34.885065, 50.600625);

        // Should move significantly toward new position
        $this->assertGreaterThan(34.884565, $result['latitude']); // At least halfway
        $this->assertGreaterThan(50.600125, $result['longitude']); // At least halfway
    }
}
