<?php

namespace Tests\Unit\Services;

use App\Services\TaskGpsMetricsAnalyzer;
use Carbon\Carbon;
use Tests\TestCase;

class TaskGpsMetricsAnalyzerTest extends TestCase
{
    private TaskGpsMetricsAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new TaskGpsMetricsAnalyzer();
    }

    /**
     * Test empty GPS data returns empty results.
     */
    public function test_analyze_returns_empty_results_for_no_data(): void
    {
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.01);
        $results = $this->analyzer->analyze($polygon);

        $this->assertEquals(0, $results['movement_distance_km']);
        $this->assertEquals(0, $results['movement_distance_meters']);
        $this->assertEquals(0, $results['movement_duration_seconds']);
        $this->assertEquals('00:00:00', $results['movement_duration_formatted']);
        $this->assertEquals(0, $results['stoppage_duration_seconds']);
        $this->assertEquals('00:00:00', $results['stoppage_duration_formatted']);
        $this->assertEquals(0, $results['stoppage_count']);
        $this->assertNull($results['device_on_time']);
        $this->assertNull($results['first_movement_time']);
        $this->assertEquals(0, $results['latest_status']);
        $this->assertEquals(0, $results['average_speed']);
    }

    /**
     * Test no polygon returns empty results.
     */
    public function test_analyze_returns_empty_results_for_no_polygon(): void
    {
        $gpsData = [
            [
                'date_time' => Carbon::now()->setTime(8, 0, 0),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, []);

        $this->assertEquals(0, $results['movement_distance_km']);
        $this->assertEquals(0, $results['movement_duration_seconds']);
    }

    /**
     * Test all points outside zone returns empty results.
     */
    public function test_all_points_outside_zone_returns_empty_results(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.005);

        // Create GPS points far outside the polygon
        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [36.0, 52.0], // Outside
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [36.001, 52.0], // Outside
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [36.002, 52.0], // Outside
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        $this->assertEquals(0, $results['movement_distance_km']);
        $this->assertEquals(0, $results['movement_duration_seconds']);
        $this->assertEquals(0, $results['stoppage_count']);
    }

    /**
     * Test single segment: tractor enters zone once and stays inside.
     */
    public function test_single_segment_all_points_inside_zone(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.01);

        // All points inside the zone, moving
        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.002, 51.002],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(30),
                'coordinate' => [35.003, 51.003],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        $this->assertGreaterThan(0, $results['movement_distance_km']);
        $this->assertEquals(30, $results['movement_duration_seconds']);
        $this->assertEquals(0, $results['stoppage_count']);
        $this->assertGreaterThan(0, $results['average_speed']);
    }

    /**
     * Test multiple segments: tractor enters, exits, and re-enters zone.
     */
    public function test_multiple_segments_entry_exit_reentry(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0) with size 0.005
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.005);

        $gpsData = [
            // First segment: inside zone
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0], // Inside
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001], // Inside
                'speed' => 10,
                'status' => 1,
            ],
            // Exit zone
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.01, 51.01], // Outside
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(30),
                'coordinate' => [35.02, 51.02], // Outside
                'speed' => 10,
                'status' => 1,
            ],
            // Re-enter zone (second segment)
            [
                'date_time' => $baseTime->copy()->addSeconds(40),
                'coordinate' => [35.001, 51.001], // Inside again
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(50),
                'coordinate' => [35.002, 51.002], // Inside
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        // Should only count time from two segments (0-10s and 40-50s = 20s total)
        // Gap between segments (10-40s) should be ignored
        $this->assertEquals(20, $results['movement_duration_seconds']);
        $this->assertGreaterThan(0, $results['movement_distance_km']);
        $this->assertEquals(0, $results['stoppage_count']);
    }

    /**
     * Test gap between segments is not counted in metrics.
     */
    public function test_gap_between_segments_is_ignored(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.003);

        $gpsData = [
            // First segment: 10 seconds inside
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0], // Inside
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001], // Inside
                'speed' => 10,
                'status' => 1,
            ],
            // Outside zone for 100 seconds (gap)
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.01, 51.01], // Outside
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(50),
                'coordinate' => [35.02, 51.02], // Outside
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(110),
                'coordinate' => [35.03, 51.03], // Outside
                'speed' => 10,
                'status' => 1,
            ],
            // Second segment: 15 seconds inside
            [
                'date_time' => $baseTime->copy()->addSeconds(120),
                'coordinate' => [35.001, 51.001], // Inside again
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(135),
                'coordinate' => [35.002, 51.002], // Inside
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        // Total should be 25 seconds (10 + 15), gap of 100s ignored
        $this->assertEquals(25, $results['movement_duration_seconds']);
        $this->assertGreaterThan(0, $results['movement_distance_km']);
    }

    /**
     * Test stoppage inside zone is counted correctly.
     */
    public function test_stoppage_inside_zone_is_counted(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.01);

        $gpsData = [
            // Moving inside zone
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
            // Stop for 80 seconds (> 60s threshold) - from 20s to 100s
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.001, 51.001],
                'speed' => 0,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(90),
                'coordinate' => [35.001, 51.001],
                'speed' => 0,
                'status' => 1,
            ],
            // Resume movement
            [
                'date_time' => $baseTime->copy()->addSeconds(100),
                'coordinate' => [35.002, 51.002],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        $this->assertEquals(1, $results['stoppage_count']);
        $this->assertEquals(80, $results['stoppage_duration_seconds']); // 20s to 100s = 80s
        $this->assertEquals(20, $results['movement_duration_seconds']); // 10s + 10s
        $this->assertGreaterThan(0, $results['movement_distance_km']);
    }

    /**
     * Test short stoppage (< 60s) inside zone is counted as movement.
     */
    public function test_short_stoppage_inside_zone_counted_as_movement(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.01);

        $gpsData = [
            // Moving inside zone
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
            // Stop for 30 seconds (< 60s threshold)
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.001, 51.001],
                'speed' => 0,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(50),
                'coordinate' => [35.001, 51.001],
                'speed' => 0,
                'status' => 1,
            ],
            // Resume movement
            [
                'date_time' => $baseTime->copy()->addSeconds(60),
                'coordinate' => [35.002, 51.002],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        // Short stoppage should be counted as movement
        $this->assertEquals(0, $results['stoppage_count']);
        $this->assertEquals(0, $results['stoppage_duration_seconds']);
        $this->assertEquals(60, $results['movement_duration_seconds']); // All 60 seconds
        $this->assertGreaterThan(0, $results['movement_distance_km']);
    }

    /**
     * Test stoppage outside zone is not counted.
     */
    public function test_stoppage_outside_zone_is_not_counted(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.003);

        $gpsData = [
            // Moving inside zone
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
            // Exit zone
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.01, 51.01], // Outside
                'speed' => 10,
                'status' => 1,
            ],
            // Stop outside zone for 100 seconds
            [
                'date_time' => $baseTime->copy()->addSeconds(30),
                'coordinate' => [35.01, 51.01],
                'speed' => 0,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(130),
                'coordinate' => [35.01, 51.01],
                'speed' => 0,
                'status' => 1,
            ],
            // Re-enter zone
            [
                'date_time' => $baseTime->copy()->addSeconds(140),
                'coordinate' => [35.001, 51.001], // Inside
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        // Stoppage was outside, so should not be counted
        $this->assertEquals(0, $results['stoppage_count']);
        $this->assertEquals(0, $results['stoppage_duration_seconds']);
        $this->assertEquals(10, $results['movement_duration_seconds']); // Only first segment
    }

    /**
     * Test multiple stoppages across multiple segments.
     */
    public function test_multiple_stoppages_across_segments(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.005);

        $gpsData = [
            // First segment: movement + stoppage
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.001, 51.001],
                'speed' => 0, // Stop 70s
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(90),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
            // Exit zone
            [
                'date_time' => $baseTime->copy()->addSeconds(100),
                'coordinate' => [35.01, 51.01], // Outside
                'speed' => 10,
                'status' => 1,
            ],
            // Re-enter zone (second segment)
            [
                'date_time' => $baseTime->copy()->addSeconds(150),
                'coordinate' => [35.001, 51.001], // Inside
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(160),
                'coordinate' => [35.002, 51.002],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(170),
                'coordinate' => [35.002, 51.002],
                'speed' => 0, // Stop 80s
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(250),
                'coordinate' => [35.002, 51.002],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        // Two stoppages across two segments
        $this->assertEquals(2, $results['stoppage_count']);
        $this->assertEquals(150, $results['stoppage_duration_seconds']); // 70 + 80
        $this->assertGreaterThan(0, $results['movement_duration_seconds']);
    }

    /**
     * Test partial segment (tractor still inside when analysis runs).
     */
    public function test_partial_segment_tractor_still_inside(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.01);

        // Tractor enters and stays inside (no exit)
        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.002, 51.002],
                'speed' => 10,
                'status' => 1,
            ],
            // Still inside at last point
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        // Partial segment should be included
        $this->assertEquals(20, $results['movement_duration_seconds']);
        $this->assertGreaterThan(0, $results['movement_distance_km']);
        $this->assertEquals(1, $results['latest_status']);
    }

    /**
     * Test first movement time is tracked correctly across segments.
     */
    public function test_first_movement_time_tracked_across_segments(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.005);

        $gpsData = [
            // First segment: 3 movements to trigger first movement
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.002, 51.002],
                'speed' => 10,
                'status' => 1,
            ],
            // Exit
            [
                'date_time' => $baseTime->copy()->addSeconds(30),
                'coordinate' => [35.01, 51.01], // Outside
                'speed' => 10,
                'status' => 1,
            ],
            // Re-enter
            [
                'date_time' => $baseTime->copy()->addSeconds(40),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        // First movement time should be set from first segment
        $this->assertNotNull($results['first_movement_time']);
        $this->assertEquals($baseTime->format('H:i:s'), $results['first_movement_time']);
    }

    /**
     * Test device on time is tracked correctly.
     */
    public function test_device_on_time_is_tracked(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.01);

        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1, // Device on
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        $this->assertNotNull($results['device_on_time']);
        $this->assertEquals($baseTime->format('H:i:s'), $results['device_on_time']);
    }

    /**
     * Test stoppage duration split (while on/off) is calculated correctly.
     */
    public function test_stoppage_duration_split_on_off(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.01);

        $gpsData = [
            // Moving
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.001],
                'speed' => 10,
                'status' => 1,
            ],
            // Stop with device on
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.001, 51.001],
                'speed' => 0,
                'status' => 1, // On
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(50),
                'coordinate' => [35.001, 51.001],
                'speed' => 0,
                'status' => 1, // On
            ],
            // Device turns off during stoppage
            [
                'date_time' => $baseTime->copy()->addSeconds(80),
                'coordinate' => [35.001, 51.001],
                'speed' => 0,
                'status' => 0, // Off
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(110),
                'coordinate' => [35.001, 51.001],
                'speed' => 0,
                'status' => 0, // Off
            ],
            // Resume
            [
                'date_time' => $baseTime->copy()->addSeconds(120),
                'coordinate' => [35.002, 51.002],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        $this->assertEquals(1, $results['stoppage_count']);
        $this->assertEquals(100, $results['stoppage_duration_seconds']); // Total stoppage
        $this->assertGreaterThan(0, $results['stoppage_duration_while_on_seconds']);
        $this->assertGreaterThan(0, $results['stoppage_duration_while_off_seconds']);
    }

    /**
     * Test three or more segments work correctly.
     */
    public function test_three_segments_entry_exit_multiple_times(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.003);

        $gpsData = [
            // Segment 1
            ['date_time' => $baseTime->copy(), 'coordinate' => [35.0, 51.0], 'speed' => 10, 'status' => 1],
            ['date_time' => $baseTime->copy()->addSeconds(10), 'coordinate' => [35.001, 51.001], 'speed' => 10, 'status' => 1],
            // Exit
            ['date_time' => $baseTime->copy()->addSeconds(20), 'coordinate' => [35.01, 51.01], 'speed' => 10, 'status' => 1],
            // Segment 2
            ['date_time' => $baseTime->copy()->addSeconds(30), 'coordinate' => [35.001, 51.001], 'speed' => 10, 'status' => 1],
            ['date_time' => $baseTime->copy()->addSeconds(40), 'coordinate' => [35.002, 51.002], 'speed' => 10, 'status' => 1],
            // Exit
            ['date_time' => $baseTime->copy()->addSeconds(50), 'coordinate' => [35.01, 51.01], 'speed' => 10, 'status' => 1],
            // Segment 3
            ['date_time' => $baseTime->copy()->addSeconds(60), 'coordinate' => [35.0, 51.0], 'speed' => 10, 'status' => 1],
            ['date_time' => $baseTime->copy()->addSeconds(70), 'coordinate' => [35.001, 51.001], 'speed' => 10, 'status' => 1],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        // Total should be 30 seconds (10 + 10 + 10)
        $this->assertEquals(30, $results['movement_duration_seconds']);
        $this->assertGreaterThan(0, $results['movement_distance_km']);
    }

    /**
     * Test edge case: single point inside zone.
     */
    public function test_single_point_inside_zone(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create polygon around (35.0, 51.0)
        $polygon = $this->createSquarePolygon(35.0, 51.0, 0.01);

        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        // Single point means no duration or distance
        $this->assertEquals(0, $results['movement_duration_seconds']);
        $this->assertEquals(0, $results['movement_distance_km']);
    }

    /**
     * Test average speed calculation.
     */
    public function test_average_speed_calculation(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Create a larger polygon to ensure all points are inside
        // Polygon centered at 35.05, 51.0 with size 0.06 to contain all points
        $polygon = $this->createSquarePolygon(35.05, 51.0, 0.06);

        // Create movement with more distance to generate meaningful speed
        // Moving about 11 km in 1 hour = 11 km/h average
        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(900), // 15 min
                'coordinate' => [35.025, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(1800), // 30 min
                'coordinate' => [35.05, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(2700), // 45 min
                'coordinate' => [35.075, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(3600), // 60 min
                'coordinate' => [35.1, 51.0], // Total ~11.1 km
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $polygon);

        $this->assertEquals(3600, $results['movement_duration_seconds']);
        $this->assertGreaterThan(5, $results['movement_distance_km']); // At least 5 km
        $this->assertGreaterThan(5, $results['average_speed']); // At least 5 km/h
    }

    /**
     * Helper method to create a square polygon around a center point.
     *
     * @param float $centerLat Center latitude
     * @param float $centerLon Center longitude
     * @param float $size Half-width of the square in degrees
     * @return array Polygon coordinates
     */
    private function createSquarePolygon(float $centerLat, float $centerLon, float $size): array
    {
        return [
            [$centerLon - $size, $centerLat - $size], // SW
            [$centerLon + $size, $centerLat - $size], // SE
            [$centerLon + $size, $centerLat + $size], // NE
            [$centerLon - $size, $centerLat + $size], // NW
            [$centerLon - $size, $centerLat - $size], // Close polygon
        ];
    }

    /**
     * Helper method to get analyzer results from GPS data array.
     */
    private function getAnalyzerResults(array $gpsData, array $polygon = []): array
    {
        // Use reflection to access private parseRecords method
        $reflection = new \ReflectionClass($this->analyzer);
        $parseMethod = $reflection->getMethod('parseRecords');
        $parseMethod->setAccessible(true);
        $parseMethod->invoke($this->analyzer, $gpsData);

        return $this->analyzer->analyze($polygon);
    }
}

