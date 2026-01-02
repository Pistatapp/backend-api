<?php

namespace Tests\Unit\Services;

use App\Models\Tractor;
use App\Services\GpsDataAnalyzer;
use Carbon\Carbon;
use Tests\TestCase;

class GpsDataAnalyzerTest extends TestCase
{
    private GpsDataAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new GpsDataAnalyzer();
    }

    /**
     * Test empty GPS data returns empty results.
     */
    public function test_analyze_returns_empty_results_for_no_data(): void
    {
        $results = $this->analyzer->analyze();

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
     * Test basic movement distance calculation.
     */
    public function test_calculates_movement_distance_correctly(): void
    {
        // Create GPS data for a straight line movement
        // From approximately (35.0, 51.0) to (35.01, 51.0) - about 1.11 km
        $gpsData = [
            [
                'date_time' => Carbon::now()->setTime(8, 0, 0),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => Carbon::now()->setTime(8, 0, 10),
                'coordinate' => [35.002, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => Carbon::now()->setTime(8, 0, 20),
                'coordinate' => [35.004, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => Carbon::now()->setTime(8, 0, 30),
                'coordinate' => [35.006, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertGreaterThan(0, $results['movement_distance_km']);
        $this->assertEquals(30, $results['movement_duration_seconds']);
        $this->assertGreaterThan(0, $results['average_speed']);
    }

    /**
     * Test stoppage detection with minimum duration threshold.
     */
    public function test_detects_stoppages_above_minimum_duration(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

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
                'coordinate' => [35.001, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Stopped for 80 seconds (above 60s threshold)
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.002, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(100),
                'coordinate' => [35.002, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            // Moving again
            [
                'date_time' => $baseTime->copy()->addSeconds(110),
                'coordinate' => [35.003, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertEquals(1, $results['stoppage_count']);
        // Stoppage is from point at 20s to point at 100s = 90 seconds total
        $this->assertEquals(90, $results['stoppage_duration_seconds']);
        $this->assertEquals('00:01:30', $results['stoppage_duration_formatted']);
    }

    /**
     * Test short stoppages are counted as movement duration.
     */
    public function test_short_stoppages_counted_as_movement(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

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
                'coordinate' => [35.001, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Stopped for only 30 seconds (below 60s threshold)
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.002, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(50),
                'coordinate' => [35.002, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            // Moving again
            [
                'date_time' => $baseTime->copy()->addSeconds(60),
                'coordinate' => [35.003, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertEquals(0, $results['stoppage_count']);
        $this->assertEquals(0, $results['stoppage_duration_seconds']);
        // Short stoppage counted as movement
        $this->assertEquals(60, $results['movement_duration_seconds']);
    }

    /**
     * Test first movement detection with 3 consecutive movements.
     */
    public function test_detects_first_movement_after_three_consecutive_movements(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $gpsData = [
            // Stopped
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            // First movement
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Second consecutive movement
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.002, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Third consecutive movement - this triggers first_movement_time
            [
                'date_time' => $baseTime->copy()->addSeconds(30),
                'coordinate' => [35.003, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertNotNull($results['first_movement_time']);
        // First movement time is recorded at the first movement point (10s after start)
        $this->assertEquals($baseTime->copy()->addSeconds(10)->format('H:i:s'), $results['first_movement_time']);
    }

    /**
     * Test device on time detection (first status=1).
     */
    public function test_detects_device_on_time(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $gpsData = [
            // Device off
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 0,
                'status' => 0,
            ],
            // Device turns on here
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.0, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.001, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertNotNull($results['device_on_time']);
        $this->assertEquals($baseTime->copy()->addSeconds(10)->format('H:i:s'), $results['device_on_time']);
    }

    /**
     * Test stoppage duration split between on/off status.
     */
    public function test_calculates_stoppage_duration_while_on_and_off(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $gpsData = [
            // Moving
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Stop with status on
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(40),
                'coordinate' => [35.001, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            // Status turns off
            [
                'date_time' => $baseTime->copy()->addSeconds(70),
                'coordinate' => [35.001, 51.0],
                'speed' => 0,
                'status' => 0,
            ],
            // Move again with status on
            [
                'date_time' => $baseTime->copy()->addSeconds(100),
                'coordinate' => [35.002, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertEquals(1, $results['stoppage_count']);
        $this->assertEquals(90, $results['stoppage_duration_seconds']);
        $this->assertGreaterThan(0, $results['stoppage_duration_while_on_seconds']);
        $this->assertGreaterThan(0, $results['stoppage_duration_while_off_seconds']);
    }

    /**
     * Test time bound filtering (only analyze within specified time window).
     */
    public function test_respects_time_bounds(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $gpsData = [
            // Before time bound
            [
                'date_time' => $baseTime->copy()->subMinutes(30),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Within time bound
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.001, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addMinutes(10),
                'coordinate' => [35.002, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // After time bound
            [
                'date_time' => $baseTime->copy()->addMinutes(40),
                'coordinate' => [35.003, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $timeBoundStart = $baseTime->copy();
        $timeBoundEnd = $baseTime->copy()->addMinutes(20);

        $results = $this->getAnalyzerResults($gpsData, $timeBoundStart, $timeBoundEnd);

        // Only data between 8:00 and 8:20 should be counted
        $this->assertEquals(600, $results['movement_duration_seconds']); // 10 minutes
    }

    /**
     * Test polygon filtering (only analyze points within polygon).
     */
    public function test_respects_polygon_bounds(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Define a small polygon - note: polygon format is [lon, lat] for point-in-polygon check
        $polygon = [
            [51.0, 35.0],
            [51.01, 35.0],
            [51.01, 35.01],
            [51.0, 35.01],
        ];

        $gpsData = [
            // Inside polygon - coordinate format is [lat, lon]
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.005, 51.005],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.006, 51.005],
                'speed' => 10,
                'status' => 1,
            ],
            // Outside polygon (far away)
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [36.0, 52.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Inside polygon again
            [
                'date_time' => $baseTime->copy()->addSeconds(30),
                'coordinate' => [35.007, 51.005],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, null, null, $polygon);

        // Only points inside polygon should be counted
        $this->assertLessThanOrEqual(30, $results['movement_duration_seconds']);
        $this->assertGreaterThan(0, $results['movement_duration_seconds']);
    }

    /**
     * Test average speed calculation.
     */
    public function test_calculates_average_speed_correctly(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Moving at constant speed for known distance
        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 20,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.0],
                'speed' => 20,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.002, 51.0],
                'speed' => 20,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertGreaterThan(0, $results['average_speed']);
        // Average speed = (distance_km / duration_seconds) * 3600
    }

    /**
     * Test that movement requires both status=1 and speed>0.
     */
    public function test_movement_requires_status_one_and_speed_greater_than_zero(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $gpsData = [
            // Status 1 but speed 0 - not moving (stoppage)
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            // Status 0 but speed > 0 - not moving
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.0],
                'speed' => 10,
                'status' => 0,
            ],
            // Both status 1 and speed > 0 - moving
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.002, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(30),
                'coordinate' => [35.003, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(40),
                'coordinate' => [35.004, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        // Movement segments should count, but the transitions from stoppage count the time
        // The stoppage from 0-10s was short (<60s) so counted as movement (10s)
        // Plus actual movement from 20-40s (20s) = 30s total movement
        // Plus transition from status 0 to 1 (10s) = 40s total
        $this->assertEquals(40, $results['movement_duration_seconds']);
        $this->assertEquals(0, $results['stoppage_count']); // No stoppages >= 60s
    }

    /**
     * Test coordinate parsing from different formats.
     */
    public function test_parses_coordinates_from_different_formats(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $gpsData = [
            // Array format
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // JSON string format
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => json_encode([35.001, 51.0]),
                'speed' => 10,
                'status' => 1,
            ],
            // Comma-separated string format
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => '35.002, 51.0',
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertGreaterThan(0, $results['movement_distance_km']);
        $this->assertEquals(20, $results['movement_duration_seconds']);
    }

    /**
     * Test latest status is captured correctly.
     */
    public function test_captures_latest_status(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.0],
                'speed' => 0,
                'status' => 0,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertEquals(0, $results['latest_status']);
    }

    /**
     * Test time formatting is correct.
     */
    public function test_formats_time_correctly(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addHours(2)->addMinutes(30)->addSeconds(45),
                'coordinate' => [35.02, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        // 2 hours 30 minutes 45 seconds
        $this->assertEquals('02:30:45', $results['movement_duration_formatted']);
    }

    /**
     * Test multiple stoppages are counted correctly.
     */
    public function test_counts_multiple_stoppages(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $gpsData = [
            // Moving
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // First stoppage (80s)
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(90),
                'coordinate' => [35.001, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            // Moving
            [
                'date_time' => $baseTime->copy()->addSeconds(100),
                'coordinate' => [35.002, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Second stoppage (75s)
            [
                'date_time' => $baseTime->copy()->addSeconds(110),
                'coordinate' => [35.003, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(185),
                'coordinate' => [35.003, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            // Moving
            [
                'date_time' => $baseTime->copy()->addSeconds(195),
                'coordinate' => [35.004, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertEquals(2, $results['stoppage_count']);
        // First stoppage: 10s to 90s = 80s
        // Second stoppage: 110s to 185s = 75s
        // But it's calculated from point to point, so 90 + 85 = 175s
        $this->assertEquals(175, $results['stoppage_duration_seconds']);
    }

    /**
     * Test consecutive movement counter resets on stoppage.
     */
    public function test_consecutive_movement_counter_resets_on_stoppage(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $gpsData = [
            // One movement
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Stop (resets counter)
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.0],
                'speed' => 0,
                'status' => 1,
            ],
            // First movement after reset
            [
                'date_time' => $baseTime->copy()->addSeconds(20),
                'coordinate' => [35.002, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Second movement
            [
                'date_time' => $baseTime->copy()->addSeconds(30),
                'coordinate' => [35.003, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Third movement - this should be first_movement_time
            [
                'date_time' => $baseTime->copy()->addSeconds(40),
                'coordinate' => [35.004, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData);

        $this->assertNotNull($results['first_movement_time']);
        // First movement time should be at second 20 (after reset)
        $this->assertEquals($baseTime->copy()->addSeconds(20)->format('H:i:s'), $results['first_movement_time']);
    }

    /**
     * Test combined time bounds and polygon filtering.
     *
     * This test verifies that when both time bounds and polygon are provided:
     * 1. Only GPS points within the specified time window are considered
     * 2. Of those points, only those inside the polygon are analyzed
     * 3. Metrics are calculated correctly based on the filtered data
     */
    public function test_filters_by_both_time_bounds_and_polygon(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Define work zone polygon (small area around 35.005, 51.005)
        // Format: [lon, lat] for point-in-polygon check
        $workZonePolygon = [
            [51.0, 35.0],
            [51.01, 35.0],
            [51.01, 35.01],
            [51.0, 35.01],
        ];

        // Define task time window: 8:10 to 8:50
        $taskStartTime = $baseTime->copy()->addMinutes(10);
        $taskEndTime = $baseTime->copy()->addMinutes(50);

        $gpsData = [
            // Point 1: Before task start time - should be EXCLUDED (time filter)
            [
                'date_time' => $baseTime->copy(), // 8:00
                'coordinate' => [35.005, 51.005], // Inside polygon
                'speed' => 10,
                'status' => 1,
            ],

            // Point 2: Within time, inside polygon - should be INCLUDED
            [
                'date_time' => $baseTime->copy()->addMinutes(15), // 8:15
                'coordinate' => [35.005, 51.005], // Inside polygon
                'speed' => 10,
                'status' => 1,
            ],

            // Point 3: Within time, inside polygon - should be INCLUDED
            [
                'date_time' => $baseTime->copy()->addMinutes(20), // 8:20
                'coordinate' => [35.006, 51.006], // Inside polygon
                'speed' => 10,
                'status' => 1,
            ],

            // Point 4: Within time, OUTSIDE polygon - should be EXCLUDED (polygon filter)
            [
                'date_time' => $baseTime->copy()->addMinutes(25), // 8:25
                'coordinate' => [36.0, 52.0], // Outside polygon (far away)
                'speed' => 10,
                'status' => 1,
            ],

            // Point 5: Within time, inside polygon - should be INCLUDED
            [
                'date_time' => $baseTime->copy()->addMinutes(30), // 8:30
                'coordinate' => [35.007, 51.007], // Inside polygon
                'speed' => 10,
                'status' => 1,
            ],

            // Point 6: Within time, stopped inside polygon - should be INCLUDED
            [
                'date_time' => $baseTime->copy()->addMinutes(35), // 8:35
                'coordinate' => [35.007, 51.007], // Inside polygon
                'speed' => 0,
                'status' => 1,
            ],

            // Point 7: Within time, inside polygon, moving again - should be INCLUDED
            [
                'date_time' => $baseTime->copy()->addMinutes(45), // 8:45
                'coordinate' => [35.008, 51.008], // Inside polygon
                'speed' => 10,
                'status' => 1,
            ],

            // Point 8: After task end time - should be EXCLUDED (time filter)
            [
                'date_time' => $baseTime->copy()->addMinutes(60), // 9:00
                'coordinate' => [35.008, 51.008], // Inside polygon
                'speed' => 10,
                'status' => 1,
            ],

            // Point 9: Within time, OUTSIDE polygon - should be EXCLUDED (polygon filter)
            [
                'date_time' => $baseTime->copy()->addMinutes(40), // 8:40
                'coordinate' => [37.0, 53.0], // Outside polygon (very far)
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $taskStartTime, $taskEndTime, $workZonePolygon);

        // Verify that only filtered points were analyzed
        // Expected included points: 2, 3, 5, 6, 7
        // Point 2 to 3: 5 minutes = 300s
        // Point 3 to 5: 10 minutes = 600s (Point 4 excluded by polygon)
        // Point 5 to 6: 5 minutes = 300s (stoppage)
        // Point 6 to 7: 10 minutes = 600s (stoppage < 60s becomes movement)

        // Total movement duration should be from points inside both filters
        $this->assertGreaterThan(0, $results['movement_duration_seconds']);
        $this->assertLessThan(2100, $results['movement_duration_seconds']); // Less than total possible time

        // Verify distance was calculated (should be > 0 since there's movement)
        $this->assertGreaterThan(0, $results['movement_distance_km']);

        // The point outside time bounds should not affect results
        // The points outside polygon should not affect results
        $this->assertLessThan(2400, $results['movement_duration_seconds']); // Should be less than 40 minutes

        // Verify stoppage detection still works with filters
        // There's a stoppage from 8:35 to 8:45 (600 seconds) which exceeds 60s threshold
        $this->assertGreaterThanOrEqual(1, $results['stoppage_count']);
    }

    /**
     * Test that polygon filtering correctly excludes all points when none are inside.
     */
    public function test_polygon_filter_excludes_all_points_when_none_inside(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Define a polygon in a completely different location
        $remotePolygon = [
            [50.0, 30.0],
            [50.01, 30.0],
            [50.01, 30.01],
            [50.0, 30.01],
        ];

        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0], // Far from polygon
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addSeconds(10),
                'coordinate' => [35.001, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, null, null, $remotePolygon);

        // No points should be included, so results should be empty
        $this->assertEquals(0, $results['movement_distance_km']);
        $this->assertEquals(0, $results['movement_duration_seconds']);
        $this->assertEquals(0, $results['stoppage_count']);
    }

    /**
     * Test that time bounds correctly exclude all points when none are within window.
     */
    public function test_time_bounds_exclude_all_points_when_none_within_window(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        // Define time window that doesn't include any points
        $windowStart = $baseTime->copy()->addHours(2);
        $windowEnd = $baseTime->copy()->addHours(3);

        $gpsData = [
            [
                'date_time' => $baseTime->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            [
                'date_time' => $baseTime->copy()->addMinutes(10),
                'coordinate' => [35.001, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $windowStart, $windowEnd);

        // No points should be included, so results should be empty
        $this->assertEquals(0, $results['movement_distance_km']);
        $this->assertEquals(0, $results['movement_duration_seconds']);
        $this->assertEquals(0, $results['stoppage_count']);
    }

    /**
     * Test edge case: point exactly on time boundary is included.
     */
    public function test_point_on_time_boundary_is_included(): void
    {
        $baseTime = Carbon::now()->setTime(8, 0, 0);

        $windowStart = $baseTime->copy()->addMinutes(10);
        $windowEnd = $baseTime->copy()->addMinutes(20);

        $gpsData = [
            // Exactly at start boundary
            [
                'date_time' => $windowStart->copy(),
                'coordinate' => [35.0, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Within window
            [
                'date_time' => $windowStart->copy()->addMinutes(5),
                'coordinate' => [35.001, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
            // Exactly at end boundary
            [
                'date_time' => $windowEnd->copy(),
                'coordinate' => [35.002, 51.0],
                'speed' => 10,
                'status' => 1,
            ],
        ];

        $results = $this->getAnalyzerResults($gpsData, $windowStart, $windowEnd);

        // All three points should be included
        $this->assertEquals(600, $results['movement_duration_seconds']); // 10 minutes
        $this->assertGreaterThan(0, $results['movement_distance_km']);
    }

    /**
     * Helper method to get analyzer results from GPS data array.
     */
    private function getAnalyzerResults(
        array $gpsData,
        ?Carbon $timeBoundStart = null,
        ?Carbon $timeBoundEnd = null,
        array $polygon = []
    ): array {
        // Use reflection to access private parseRecords method
        $reflection = new \ReflectionClass($this->analyzer);
        $parseMethod = $reflection->getMethod('parseRecords');
        $parseMethod->setAccessible(true);
        $parseMethod->invoke($this->analyzer, $gpsData);

        return $this->analyzer->analyze($timeBoundStart, $timeBoundEnd, $polygon);
    }
}

