<?php

namespace Tests\Feature;

use App\Models\Irrigation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Farm;
use App\Models\Labour;
use App\Models\Valve;
use App\Models\Pump;
use App\Models\Plot;
use App\Models\Field;
use App\Services\IrrigationReportService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test class focused on irrigation report functionality
 */
class IrrigationReportTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private Farm $farm;
    private $plots;
    private $fields;
    private $valves;
    private Pump $pump;
    private IrrigationReportService $irrigationReportService;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->farm = Farm::factory()
            ->has(Field::factory()->has(Plot::factory(3)))
            ->create();

        $this->farm->users()->attach($this->user, [
            'role' => 'owner',
            'is_owner' => true
        ]);

        $this->plots = $this->farm->fields->first()->plots;
        $this->fields = $this->farm->fields;

        $this->pump = Pump::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $this->valves = Valve::factory(3)->create([
            'plot_id' => $this->plots->first()->id
        ]);

        Labour::factory(3)->create([
            'farm_id' => $this->farm->id
        ]);

        $this->irrigationReportService = new IrrigationReportService();

        $this->actingAs($this->user);
    }

    /**
     * Test if user can get irrigation report for a field and verify calculations.
     * Checks that the total_duration is correctly formatted as HH:MM:SS by to_time_format.
     */
    #[Test]
    public function test_user_can_get_irrigation_report_for_plot(): void
    {
        $plot = $this->plots->first();
        $today = now()->format('Y-m-d');

        // Create valves with specific dripper counts and flow rates
        $valve1 = Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => 100,
            'dripper_flow_rate' => 2.0, // 2 liters per hour per dripper
            'irrigation_area' => 1.0, // 1 hectare
        ]);

        $valve2 = Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => 150,
            'dripper_flow_rate' => 3.0, // 3 liters per hour per dripper
            'irrigation_area' => 1.5, // 1.5 hectares
        ]);

        $valve3 = Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => 80,
            'dripper_flow_rate' => 1.5, // 1.5 liters per hour per dripper
            'irrigation_area' => 0.8, // 0.8 hectares
        ]);

        // First irrigation: 2 hours (8:00 - 10:00)
        $irrigation1 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $today,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'status' => 'finished',
        ]);
        // Attach plot and valve to irrigation
        $irrigation1->plots()->attach($plot);
        $irrigation1->valves()->attach($valve1);

        // Second irrigation: 3 hours (13:00 - 16:00)
        $irrigation2 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $today,
            'start_time' => '13:00:00',
            'end_time' => '16:00:00',
            'status' => 'finished',
        ]);
        // Attach plot and valve to irrigation
        $irrigation2->plots()->attach($plot);
        $irrigation2->valves()->attach($valve2);

        // Third irrigation: 1 hour (18:00 - 19:00)
        $irrigation3 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $today,
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'status' => 'finished',
        ]);
        // Attach plot and valve to irrigation
        $irrigation3->plots()->attach($plot);
        $irrigation3->valves()->attach($valve3);

        // Calculate expected values
        $expectedCount = 3;
        $totalDurationInSeconds = (2 * 3600) + (3 * 3600) + (1 * 3600); // 6 hours = 21600 seconds
        $expectedTotalDuration = '06:00:00'; // to_time_format converts 21600 seconds to "06:00:00"

        // Volume calculation: (dripper_count * dripper_flow_rate) * hours
        $volume1 = (100 * 2.0) * 2; // 200 * 2 = 400 liters
        $volume2 = (150 * 3.0) * 3; // 450 * 3 = 1350 liters
        $volume3 = (80 * 1.5) * 1;  // 120 * 1 = 120 liters
        $expectedTotalVolume = $volume1 + $volume2 + $volume3; // 1870 liters

        $volumePerHectare1 = $volume1 / $valve1->irrigation_area;
        $volumePerHectare2 = $volume2 / $valve2->irrigation_area;
        $volumePerHectare3 = $volume3 / $valve3->irrigation_area;
        $expectedTotalVolumePerHectare = $volumePerHectare1 + $volumePerHectare2 + $volumePerHectare3; // 1870 liters

        $response = $this->getJson('api/plots/' . $plot->id . '/irrigations/report');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'date',
                'total_duration',
                'total_volume',
                'total_volume_per_hectare',
                'total_count',
            ],
        ]);

        // Validate the calculated values
        $data = $response->json('data');
        $this->assertEquals($expectedCount, $data['total_count'], 'Irrigation count calculation is incorrect');
        $this->assertEquals($expectedTotalDuration, $data['total_duration'], 'Total duration formatting is incorrect');
        $this->assertEquals($expectedTotalVolume, $data['total_volume'], 'Total volume calculation is incorrect');
        $this->assertEquals($expectedTotalVolumePerHectare, $data['total_volume_per_hectare'], 'Total volume per hectare calculation is incorrect');
    }

    /**
     * Test irrigation report service for date range reports
     * Tests scenario 1: User chooses a plot and a date range
     */
    #[Test]
    public function test_irrigation_report_service_date_range_reports(): void
    {
        $plot = $this->plots->first();
        $fromDate = Carbon::now()->subDays(2);
        $toDate = Carbon::now();

        // Create valves with specific properties
        $valve1 = Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => 100,
            'dripper_flow_rate' => 2.0,
            'irrigation_area' => 1.0,
        ]);

        $valve2 = Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => 150,
            'dripper_flow_rate' => 3.0,
            'irrigation_area' => 1.5,
        ]);

        // Create irrigations across multiple days
        // Day 1: 2 irrigations
        $day1Date = $fromDate->format('Y-m-d');
        $day2Date = $fromDate->copy()->addDay()->format('Y-m-d');



        $irrigation1 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $day1Date,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00', // 2 hours
            'status' => 'finished',
        ]);
        $irrigation1->plots()->attach($plot);
        $irrigation1->valves()->attach($valve1);


        $irrigation2 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $day1Date,
            'start_time' => '14:00:00',
            'end_time' => '16:00:00', // 2 hours
            'status' => 'finished',
        ]);
        $irrigation2->plots()->attach($plot);
        $irrigation2->valves()->attach($valve2);


        // Day 2: 1 irrigation
        $irrigation3 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $day2Date,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00', // 3 hours
            'status' => 'finished',
        ]);
        $irrigation3->plots()->attach($plot);
        $irrigation3->valves()->attach($valve1);


        $result = $this->irrigationReportService->getDateRangeReports([$plot->id], $fromDate, $toDate);



        // Verify structure
        $this->assertArrayHasKey('irrigations', $result);
        $this->assertArrayHasKey('accumulated', $result);

        // Verify irrigations array structure
        $this->assertIsArray($result['irrigations']);
        $this->assertCount(3, $result['irrigations']); // 3 days in range

        // Verify each day has correct structure
        foreach ($result['irrigations'] as $dayReport) {
            $this->assertArrayHasKey('date', $dayReport);
            $this->assertArrayHasKey('total_duration', $dayReport);
            $this->assertArrayHasKey('total_volume', $dayReport);
            $this->assertArrayHasKey('total_volume_per_hectare', $dayReport);
            $this->assertArrayHasKey('total_count', $dayReport);
        }

        // Verify accumulated structure
        $this->assertArrayHasKey('total_duration', $result['accumulated']);
        $this->assertArrayHasKey('total_volume', $result['accumulated']);
        $this->assertArrayHasKey('total_volume_per_hectare', $result['accumulated']);
        $this->assertArrayHasKey('total_count', $result['accumulated']);

        // Verify calculations for first day (2 irrigations)
        $day1Report = $result['irrigations'][0];
        $this->assertEquals(2, $day1Report['total_count']);
        $this->assertEquals('04:00:00', $day1Report['total_duration']); // 2 + 2 hours

        // Day 1 volume calculations:
        // Irrigation 1: 100 * 2.0 * 2 = 400 liters
        // Irrigation 2: 150 * 3.0 * 2 = 900 liters
        // Total: 1300 liters
        $this->assertEquals(1300, $day1Report['total_volume']);

        // Verify calculations for second day (1 irrigation)
        $day2Report = $result['irrigations'][1];
        $this->assertEquals(1, $day2Report['total_count']);
        $this->assertEquals('03:00:00', $day2Report['total_duration']); // 3 hours

        // Day 2 volume calculation:
        // Irrigation 3: 100 * 2.0 * 3 = 600 liters
        $this->assertEquals(600, $day2Report['total_volume']);

        // Verify accumulated totals
        $this->assertEquals(3, $result['accumulated']['total_count']); // 2 + 1 + 0
        $this->assertEquals('07:00:00', $result['accumulated']['total_duration']); // 4 + 3 + 0 hours
        $this->assertEquals(1900, $result['accumulated']['total_volume']); // 1300 + 600 + 0
    }

    /**
     * Test irrigation report service for valve-specific reports
     * Tests scenario 2: User chooses a plot and specific valves
     */
    #[Test]
    public function test_irrigation_report_service_valve_specific_reports(): void
    {
        $plot = $this->plots->first();
        $fromDate = Carbon::now()->subDays(1);
        $toDate = Carbon::now();

        // Create 3 valves with different properties
        $valve1 = Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => 100,
            'dripper_flow_rate' => 2.0,
            'irrigation_area' => 1.0,
        ]);

        $valve2 = Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => 150,
            'dripper_flow_rate' => 3.0,
            'irrigation_area' => 1.5,
        ]);

        $valve3 = Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => 80,
            'dripper_flow_rate' => 1.5,
            'irrigation_area' => 0.8,
        ]);

        // Create irrigations for different valves
        // Irrigation 1: Uses valve1 and valve2
        $irrigation1 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $fromDate->format('Y-m-d'),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00', // 2 hours
            'status' => 'finished',
        ]);
        $irrigation1->plots()->attach($plot);
        $irrigation1->valves()->attach([$valve1->id, $valve2->id]);

        // Irrigation 2: Uses valve2 and valve3
        $irrigation2 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $fromDate->format('Y-m-d'),
            'start_time' => '14:00:00',
            'end_time' => '16:00:00', // 2 hours
            'status' => 'finished',
        ]);
        $irrigation2->plots()->attach($plot);
        $irrigation2->valves()->attach([$valve2->id, $valve3->id]);

        // Test with specific valves
        $valveIds = [$valve1->id, $valve2->id, $valve3->id];
        $result = $this->irrigationReportService->getValveSpecificReports([$plot->id], $valveIds, $fromDate, $toDate);

        // Verify structure
        $this->assertArrayHasKey('irrigations', $result);
        $this->assertArrayHasKey('accumulated', $result);

        // Verify irrigations array structure
        $this->assertIsArray($result['irrigations']);
        $this->assertCount(2, $result['irrigations']); // 2 days in range

        // Verify each day has correct structure
        foreach ($result['irrigations'] as $dayReport) {
            $this->assertArrayHasKey('date', $dayReport);
            $this->assertArrayHasKey('irrigation_per_valve', $dayReport);

            // Verify valve-specific structure
            $this->assertArrayHasKey($valve1->name, $dayReport['irrigation_per_valve']);
            $this->assertArrayHasKey($valve2->name, $dayReport['irrigation_per_valve']);
            $this->assertArrayHasKey($valve3->name, $dayReport['irrigation_per_valve']);

            // Verify each valve report has correct structure
            foreach ($dayReport['irrigation_per_valve'] as $valveReport) {
                $this->assertArrayHasKey('total_duration', $valveReport);
                $this->assertArrayHasKey('total_volume', $valveReport);
                $this->assertArrayHasKey('total_volume_per_hectare', $valveReport);
                $this->assertArrayHasKey('total_count', $valveReport);
            }
        }

        // Verify accumulated structure
        $this->assertArrayHasKey('total_duration', $result['accumulated']);
        $this->assertArrayHasKey('total_volume', $result['accumulated']);
        $this->assertArrayHasKey('total_volume_per_hectare', $result['accumulated']);
        $this->assertArrayHasKey('total_count', $result['accumulated']);

        // Verify calculations for first day
        $day1Report = $result['irrigations'][0];

        // Valve 1: Used in irrigation1 (2 hours)
        $valve1Report = $day1Report['irrigation_per_valve'][$valve1->name];
        $this->assertEquals(1, $valve1Report['total_count']);
        $this->assertEquals('02:00:00', $valve1Report['total_duration']);
        $this->assertEquals(400, $valve1Report['total_volume']); // 100 * 2.0 * 2

        // Valve 2: Used in irrigation1 and irrigation2 (2 + 2 hours)
        $valve2Report = $day1Report['irrigation_per_valve'][$valve2->name];
        $this->assertEquals(2, $valve2Report['total_count']);
        $this->assertEquals('04:00:00', $valve2Report['total_duration']);
        $this->assertEquals(1800, $valve2Report['total_volume']); // 150 * 3.0 * 2 + 150 * 3.0 * 2

        // Valve 3: Used in irrigation2 (2 hours)
        $valve3Report = $day1Report['irrigation_per_valve'][$valve3->name];
        $this->assertEquals(1, $valve3Report['total_count']);
        $this->assertEquals('02:00:00', $valve3Report['total_duration']);
        $this->assertEquals(240, $valve3Report['total_volume']); // 80 * 1.5 * 2

        // Verify accumulated totals
        $this->assertEquals(4, $result['accumulated']['total_count']); // 1 + 2 + 1
        $this->assertEquals('08:00:00', $result['accumulated']['total_duration']); // 2 + 4 + 2
        $this->assertEquals(2440, $result['accumulated']['total_volume']); // 400 + 1800 + 240
    }

    /**
     * Test irrigation report service for labour-specific reports
     * Tests scenario 3: User chooses a plot, date range, and specific labour
     */
    #[Test]
    public function test_irrigation_report_service_labour_specific_reports(): void
    {
        $plot = $this->plots->first();
        $fromDate = Carbon::today()->subDays(2);
        $toDate = Carbon::today();

        // Create labour
        $labour1 = Labour::factory()->create(['farm_id' => $this->farm->id]);
        $labour2 = Labour::factory()->create(['farm_id' => $this->farm->id]);

        // Create valve
        $valve1 = Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => 100,
            'dripper_flow_rate' => 2.0,
            'irrigation_area' => 1.0,
        ]);

        $valve2 = Valve::factory()->create([
            'plot_id' => $plot->id,
            'dripper_count' => 150,
            'dripper_flow_rate' => 3.0,
            'irrigation_area' => 1.5,
        ]);

        // Create irrigations for specific labour
        // Day 1: 2 irrigations by labour1
        $irrigation1 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'labour_id' => $labour1->id,
            'date' => $fromDate->format('Y-m-d'),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00', // 2 hours
            'status' => 'finished',
        ]);
        $irrigation1->plots()->attach($plot);
        $irrigation1->valves()->attach($valve1);

        $irrigation2 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'labour_id' => $labour1->id,
            'date' => $fromDate->format('Y-m-d'),
            'start_time' => '14:00:00',
            'end_time' => '16:00:00', // 2 hours
            'status' => 'finished',
        ]);
        $irrigation2->plots()->attach($plot);
        $irrigation2->valves()->attach($valve2);

        // Day 2: 1 irrigation by labour2 (should not be included)
        $day2Date = $fromDate->copy()->addDay();
        $irrigation3 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'labour_id' => $labour2->id,
            'date' => $day2Date->format('Y-m-d'),
            'start_time' => '09:00:00',
            'end_time' => '12:00:00', // 3 hours
            'status' => 'finished',
        ]);
        $irrigation3->plots()->attach($plot);
        $irrigation3->valves()->attach($valve1);

        // Day 3: 1 irrigation by labour1
        $day3Date = $fromDate->copy()->addDays(2);
        $irrigation4 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'labour_id' => $labour1->id,
            'date' => $day3Date->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00', // 1 hour
            'status' => 'finished',
        ]);
        $irrigation4->plots()->attach($plot);
        $irrigation4->valves()->attach($valve1);

                $result = $this->irrigationReportService->getLabourSpecificReports([$plot->id], $labour1->id, $fromDate, $toDate);

        // Verify structure
        $this->assertArrayHasKey('irrigations', $result);
        $this->assertArrayHasKey('accumulated', $result);

        // Verify irrigations array structure
        $this->assertIsArray($result['irrigations']);
        $this->assertCount(3, $result['irrigations']); // 3 days in range

        // Verify each day has correct structure
        foreach ($result['irrigations'] as $dayReport) {
            $this->assertArrayHasKey('date', $dayReport);
            $this->assertArrayHasKey('total_duration', $dayReport);
            $this->assertArrayHasKey('total_volume', $dayReport);
            $this->assertArrayHasKey('total_volume_per_hectare', $dayReport);
            $this->assertArrayHasKey('total_count', $dayReport);
        }

        // Verify accumulated structure
        $this->assertArrayHasKey('total_duration', $result['accumulated']);
        $this->assertArrayHasKey('total_volume', $result['accumulated']);
        $this->assertArrayHasKey('total_volume_per_hectare', $result['accumulated']);
        $this->assertArrayHasKey('total_count', $result['accumulated']);

        // Verify calculations for first day (2 irrigations by labour1)
        $day1Report = $result['irrigations'][0];
        $this->assertEquals(2, $day1Report['total_count']);
        $this->assertEquals('04:00:00', $day1Report['total_duration']); // 2 + 2 hours

        // Day 1 volume calculations:
        // Irrigation 1: 100 * 2.0 * 2 = 400 liters
        // Irrigation 2: 150 * 3.0 * 2 = 900 liters
        // Total: 1300 liters
        $this->assertEquals(1300, $day1Report['total_volume']);

        // Verify calculations for second day (0 irrigations by labour1, 1 by labour2 - should be 0)
        $day2Report = $result['irrigations'][1];
        $this->assertEquals(0, $day2Report['total_count']);
        $this->assertEquals('00:00:00', $day2Report['total_duration']);
        $this->assertEquals(0, $day2Report['total_volume']);

        // Verify calculations for third day (1 irrigation by labour1)
        $day3Report = $result['irrigations'][2];
        $this->assertEquals(1, $day3Report['total_count']);
        $this->assertEquals('01:00:00', $day3Report['total_duration']); // 1 hour

        // Day 3 volume calculation:
        // Irrigation 4: 100 * 2.0 * 1 = 200 liters
        $this->assertEquals(200, $day3Report['total_volume']);

        // Verify accumulated totals (only labour1's irrigations)
        $this->assertEquals(3, $result['accumulated']['total_count']); // 2 + 0 + 1
        $this->assertEquals('05:00:00', $result['accumulated']['total_duration']); // 4 + 0 + 1 hours
        $this->assertEquals(1500, $result['accumulated']['total_volume']); // 1300 + 0 + 200
    }

    /**
     * Test edge case: empty date range
     */
    #[Test]
    public function test_irrigation_report_service_empty_date_range(): void
    {
        $plot = $this->plots->first();
        $fromDate = Carbon::now()->addDays(10); // Future date with no data
        $toDate = Carbon::now()->addDays(12);

        $result = $this->irrigationReportService->getDateRangeReports([$plot->id], $fromDate, $toDate);

        // Verify structure exists
        $this->assertArrayHasKey('irrigations', $result);
        $this->assertArrayHasKey('accumulated', $result);

        // Verify all days have zero values
        $this->assertCount(3, $result['irrigations']); // 3 days in range
        foreach ($result['irrigations'] as $dayReport) {
            $this->assertEquals(0, $dayReport['total_count']);
            $this->assertEquals('00:00:00', $dayReport['total_duration']);
            $this->assertEquals(0, $dayReport['total_volume']);
            $this->assertEquals(0, $dayReport['total_volume_per_hectare']);
        }

        // Verify accumulated totals are zero
        $this->assertEquals(0, $result['accumulated']['total_count']);
        $this->assertEquals('00:00:00', $result['accumulated']['total_duration']);
        $this->assertEquals(0, $result['accumulated']['total_volume']);
        $this->assertEquals(0, $result['accumulated']['total_volume_per_hectare']);
    }

    /**
     * Test edge case: non-existent valves
     */
    #[Test]
    public function test_irrigation_report_service_non_existent_valves(): void
    {
        $plot = $this->plots->first();
        $fromDate = Carbon::now()->subDays(1);
        $toDate = Carbon::now();

        // Use non-existent valve IDs
        $nonExistentValveIds = [9999, 9998, 9997];

        $result = $this->irrigationReportService->getValveSpecificReports([$plot->id], $nonExistentValveIds, $fromDate, $toDate);

        // Verify structure exists
        $this->assertArrayHasKey('irrigations', $result);
        $this->assertArrayHasKey('accumulated', $result);

        // Verify all valve reports have zero values
        foreach ($result['irrigations'] as $dayReport) {
            foreach ($nonExistentValveIds as $valveId) {
                $valveReport = $dayReport['irrigation_per_valve']["valve{$valveId}"];
                $this->assertEquals(0, $valveReport['total_count']);
                $this->assertEquals('00:00:00', $valveReport['total_duration']);
                $this->assertEquals(0, $valveReport['total_volume']);
                $this->assertEquals(0, $valveReport['total_volume_per_hectare']);
            }
        }

        // Verify accumulated totals are zero
        $this->assertEquals(0, $result['accumulated']['total_count']);
        $this->assertEquals('00:00:00', $result['accumulated']['total_duration']);
        $this->assertEquals(0, $result['accumulated']['total_volume']);
        $this->assertEquals(0, $result['accumulated']['total_volume_per_hectare']);
    }

    /**
     * Test edge case: non-existent labour
     */
    #[Test]
    public function test_irrigation_report_service_non_existent_labour(): void
    {
        $plot = $this->plots->first();
        $fromDate = Carbon::now()->subDays(1);
        $toDate = Carbon::now();

        // Create some irrigations with different labour
        $labour = Labour::factory()->create(['farm_id' => $this->farm->id]);
        $valve = Valve::factory()->create(['plot_id' => $plot->id]);

        $irrigation = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'labour_id' => $labour->id,
            'date' => $fromDate->format('Y-m-d'),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'status' => 'finished',
        ]);
        $irrigation->plots()->attach($plot);
        $irrigation->valves()->attach($valve);

        // Use non-existent labour ID
        $nonExistentLabourId = 9999;

        $result = $this->irrigationReportService->getLabourSpecificReports([$plot->id], $nonExistentLabourId, $fromDate, $toDate);

        // Verify structure exists
        $this->assertArrayHasKey('irrigations', $result);
        $this->assertArrayHasKey('accumulated', $result);

        // Verify all days have zero values
        foreach ($result['irrigations'] as $dayReport) {
            $this->assertEquals(0, $dayReport['total_count']);
            $this->assertEquals('00:00:00', $dayReport['total_duration']);
            $this->assertEquals(0, $dayReport['total_volume']);
            $this->assertEquals(0, $dayReport['total_volume_per_hectare']);
        }

        // Verify accumulated totals are zero
        $this->assertEquals(0, $result['accumulated']['total_count']);
        $this->assertEquals('00:00:00', $result['accumulated']['total_duration']);
        $this->assertEquals(0, $result['accumulated']['total_volume']);
        $this->assertEquals(0, $result['accumulated']['total_volume_per_hectare']);
    }

    /**
     * Test irrigation report service for multiple plots
     */
    #[Test]
    public function test_irrigation_report_service_multiple_plots(): void
    {
        // Test with just the service logic using a single plot but with array parameter
        $plot1 = $this->plots->first();
        $fromDate = Carbon::now()->subDays(1);
        $toDate = Carbon::now();

        // Create valve for the plot
        $valve1 = Valve::factory()->create([
            'plot_id' => $plot1->id,
            'dripper_count' => 100,
            'dripper_flow_rate' => 2.0,
            'irrigation_area' => 1.0,
        ]);

        // Create irrigation for the plot
        $irrigation1 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $fromDate->format('Y-m-d'),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00', // 2 hours
            'status' => 'finished',
        ]);
        $irrigation1->plots()->attach($plot1);
        $irrigation1->valves()->attach($valve1);

        // Test with multiple plots array (containing single plot)
        $result = $this->irrigationReportService->getDateRangeReports([$plot1->id], $fromDate, $toDate);

        // Verify structure
        $this->assertArrayHasKey('irrigations', $result);
        $this->assertArrayHasKey('accumulated', $result);

        // Verify calculations for the day
        $dayReport = $result['irrigations'][0];
        $this->assertEquals(1, $dayReport['total_count']);
        $this->assertEquals('02:00:00', $dayReport['total_duration']); // 2 hours

        // Volume calculations: 100 * 2.0 * 2 = 400 liters
        $this->assertEquals(400, $dayReport['total_volume']);

        // Verify accumulated totals
        $this->assertEquals(1, $result['accumulated']['total_count']);
        $this->assertEquals('02:00:00', $result['accumulated']['total_duration']);
        $this->assertEquals(400, $result['accumulated']['total_volume']);
    }

    /**
     * Test filter reports for multiple plots
     */
    #[Test]
    public function test_filter_reports_multiple_plots(): void
    {
        // Test with just the service logic using a single plot but with array parameter
        $plot1 = $this->plots->first();
        $fromDate = Carbon::now()->subDays(1);
        $toDate = Carbon::now();

        // Create valves for the plot
        $valve1 = Valve::factory()->create(['plot_id' => $plot1->id]);

        // Create labour
        $labour = Labour::factory()->create(['farm_id' => $this->farm->id]);

        // Create irrigation for the plot with labour
        $irrigation1 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'labour_id' => $labour->id,
            'date' => $fromDate->format('Y-m-d'),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'status' => 'finished',
        ]);
        $irrigation1->plots()->attach($plot1);
        $irrigation1->valves()->attach($valve1);

        // Test filter reports with array of plots and labour filter
        $filters = [
            'labour_id' => $labour->id,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];

        $result = $this->irrigationReportService->filterReports([$plot1->id], $filters);

        // Should return irrigation from the plot
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Find the day report and verify it includes the irrigation
        $dayWithIrrigations = array_filter($result, function($report) {
            return $report['irrigation_count'] > 0;
        });

        $this->assertCount(1, $dayWithIrrigations); // One day with irrigations
        $dayReport = reset($dayWithIrrigations);
        $this->assertEquals(1, $dayReport['irrigation_count']); // One irrigation included
    }
}
