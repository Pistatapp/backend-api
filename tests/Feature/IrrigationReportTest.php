<?php

namespace Tests\Feature;

use App\Models\Irrigation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Database\Seeders\RolePermissionSeeder;
use App\Models\Farm;
use App\Models\Labour;
use App\Models\Field;
use App\Models\Valve;
use App\Models\Pump;

/**
 * Test class focused on irrigation report functionality
 */
class IrrigationReportTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private $user;
    private $farm;
    private $fields;
    private $valves;
    private $pump;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->seed(RolePermissionSeeder::class);

        $this->user->assignRole('admin');

        $this->farm = Farm::factory()->create();

        $this->fields = Field::factory(3)->create([
            'farm_id' => $this->farm->id
        ]);

        $this->pump = Pump::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $this->valves = Valve::factory(3)->create([
            'pump_id' => $this->pump->id,
            'field_id' => $this->fields->first()->id
        ]);

        $this->farm->users()->attach($this->user, [
            'role' => 'owner',
            'is_owner' => true
        ]);

        Labour::factory(3)->create([
            'farm_id' => $this->farm->id
        ]);

        $this->actingAs($this->user);
    }

    /**
     * Test if user can get irrigation report for a field and verify calculations.
     * Checks that the total_duration is correctly formatted as HH:MM by to_time_format.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_get_irrigation_report_for_field(): void
    {
        $field = $this->fields->first();
        $today = now()->format('Y-m-d');

        // Create valves with specific flow rates
        $valve1 = Valve::factory()->create([
            'pump_id' => $this->pump->id,
            'field_id' => $field->id,
            'flow_rate' => 10, // 10 liters per minute
        ]);

        $valve2 = Valve::factory()->create([
            'pump_id' => $this->pump->id,
            'field_id' => $field->id,
            'flow_rate' => 15, // 15 liters per minute
        ]);

        $valve3 = Valve::factory()->create([
            'pump_id' => $this->pump->id,
            'field_id' => $field->id,
            'flow_rate' => 5, // 5 liters per minute
        ]);

        // First irrigation: 2 hours (8:00 - 10:00) - 120 minutes
        $irrigation1 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $today,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'status' => 'finished',
        ]);
        // Attach field and valve to irrigation
        $irrigation1->fields()->attach($field);
        $irrigation1->valves()->attach($valve1);

        // Second irrigation: 3 hours (13:00 - 16:00) - 180 minutes
        $irrigation2 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $today,
            'start_time' => '13:00:00',
            'end_time' => '16:00:00',
            'status' => 'finished',
        ]);
        // Attach field and valve to irrigation
        $irrigation2->fields()->attach($field);
        $irrigation2->valves()->attach($valve2);

        // Third irrigation: 1 hour (18:00 - 19:00) - 60 minutes
        $irrigation3 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $today,
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'status' => 'finished',
        ]);
        // Attach field and valve to irrigation
        $irrigation3->fields()->attach($field);
        $irrigation3->valves()->attach($valve3);

        // Calculate expected values
        $expectedCount = 3;
        $rawTotalDuration = 360; // 120 + 180 + 60 minutes
        $expectedTotalDuration = '06:00'; // to_time_format converts 360 minutes to "06:00"

        // Volume calculation: duration in minutes * flow rate
        $volume1 = 120 * 10; // 1200 liters
        $volume2 = 180 * 15; // 2700 liters
        $volume3 = 60 * 5;   // 300 liters
        $expectedTotalVolume = $volume1 + $volume2 + $volume3; // 4200 liters

        $response = $this->getJson('api/fields/'. $field->id.'/irrigations/report');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'date',
                'total_duration',
                'total_volume',
                'irrigation_count',
            ],
        ]);

        // Validate the calculated values
        $data = $response->json('data');
        $this->assertEquals($expectedCount, $data['irrigation_count'], 'Irrigation count calculation is incorrect');
        $this->assertEquals($expectedTotalDuration, $data['total_duration'], 'Total duration formatting is incorrect');
        $this->assertEquals($expectedTotalVolume, $data['total_volume'], 'Total volume calculation is incorrect');
    }

    /**
     * Test if user can filter irrigation reports by field and verify report calculations.
     * Validates that duration, volume, and count calculations are correct.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_filter_irrigation_reports_by_field(): void
    {
        $field = $this->fields->first();

        // Current date for testing
        $date = now();
        $formattedDate = $date->format('Y-m-d');
        $jalaliDate = jdate($date)->format('Y/m/d');

        // Create valves with specific flow rates for testing
        $valve1 = Valve::factory()->create([
            'pump_id' => $this->pump->id,
            'field_id' => $field->id,
            'flow_rate' => 12, // 12 liters per minute
        ]);

        $valve2 = Valve::factory()->create([
            'pump_id' => $this->pump->id,
            'field_id' => $field->id,
            'flow_rate' => 8,  // 8 liters per minute
        ]);

        // First irrigation: 1.5 hours (7:30 - 9:00) - 90 minutes
        $irrigation1 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $formattedDate,
            'start_time' => '07:30:00',
            'end_time' => '09:00:00',
            'status' => 'finished',
        ]);
        // Attach field and valve to irrigation
        $irrigation1->fields()->attach($field);
        $irrigation1->valves()->attach($valve1);

        // Second irrigation: 2 hours (14:00 - 16:00) - 120 minutes
        $irrigation2 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $formattedDate,
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
            'status' => 'finished',
        ]);
        // Attach field and valve to irrigation
        $irrigation2->fields()->attach($field);
        $irrigation2->valves()->attach($valve2);

        // Calculate expected values
        $expectedCount = 2;
        $rawTotalDuration = 90 + 120; // 210 minutes
        $expectedTotalDuration = '03:30'; // to_time_format converts 210 minutes to "03:30"

        // Volume calculation: duration in minutes * flow rate
        $volume1 = 90 * 12; // 1080 liters
        $volume2 = 120 * 8; // 960 liters
        $expectedTotalVolume = $volume1 + $volume2; // 2040 liters

        // Using direct URL instead of named route
        $response = $this->postJson("/api/farms/{$this->farm->id}/irrigations/reports", [
            'field_id' => $field->id,
            'from_date' => $jalaliDate,
            'to_date' => $jalaliDate,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['date', 'total_duration', 'total_volume', 'irrigation_count']]]);

        // Validate the calculated values in the report
        $reportData = $response->json('data')[0]; // Get the first day's report
        $this->assertEquals($expectedCount, $reportData['irrigation_count'], 'Irrigation count calculation is incorrect');
        $this->assertEquals($expectedTotalDuration, $reportData['total_duration'], 'Total duration formatting is incorrect');
        $this->assertEquals($expectedTotalVolume, $reportData['total_volume'], 'Total volume calculation is incorrect');
    }

    /**
     * Test if user can filter irrigation reports by valve and verify report calculations.
     * Validates that duration, volume, and count calculations are correct for valve-based reports.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_filter_irrigation_reports_by_valve(): void
    {
        // Get a valve for testing
        $valve = $this->valves->first();

        // Current date for testing
        $date = now();
        $formattedDate = $date->format('Y-m-d');
        $jalaliDate = jdate($date)->format('Y/m/d');

        // Update the flow rate of the valve to a known value for predictable calculations
        $valve->update([
            'flow_rate' => 20, // 20 liters per minute
        ]);

        // First irrigation: 2.5 hours (06:00 - 08:30) - 150 minutes
        $irrigation1 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $formattedDate,
            'start_time' => '06:00:00',
            'end_time' => '08:30:00',
            'status' => 'finished', // Changed from 'completed' to 'finished'
        ]);
        // Attach valve to irrigation
        $irrigation1->valves()->attach($valve);

        // Second irrigation: 1 hour (14:00 - 15:00) - 60 minutes
        $irrigation2 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $formattedDate,
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
            'status' => 'finished', // Changed from 'completed' to 'finished'
        ]);
        // Attach valve to irrigation
        $irrigation2->valves()->attach($valve);

        // Create another irrigation with a different valve (should not be counted)
        $otherValve = Valve::factory()->create([
            'pump_id' => $this->pump->id,
            'field_id' => $this->fields->first()->id,
        ]);

        $irrigation3 = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'farm_id' => $this->farm->id,
            'date' => $formattedDate,
            'start_time' => '16:00:00',
            'end_time' => '17:30:00',
            'status' => 'finished', // Changed from 'completed' to 'finished'
        ]);
        $irrigation3->valves()->attach($otherValve);

        // Calculate expected values
        $expectedCount = 2;
        $rawTotalDuration = 150 + 60; // 210 minutes
        $expectedTotalDuration = '03:30'; // to_time_format converts 210 minutes to "03:30"

        // Volume calculation: duration in minutes * flow rate
        $volume1 = 150 * 20; // 3000 liters
        $volume2 = 60 * 20;  // 1200 liters
        $expectedTotalVolume = $volume1 + $volume2; // 4200 liters

        // Send request to filter reports by valve
        $response = $this->postJson("/api/farms/{$this->farm->id}/irrigations/reports", [
            'valve_id' => $valve->id,
            'from_date' => $jalaliDate,
            'to_date' => $jalaliDate,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['date', 'total_duration', 'total_volume', 'irrigation_count']]]);

        // Validate the calculated values in the report
        $reportData = $response->json('data')[0]; // Get the first day's report
        $this->assertEquals($expectedCount, $reportData['irrigation_count'], 'Irrigation count calculation is incorrect');
        $this->assertEquals($expectedTotalDuration, $reportData['total_duration'], 'Total duration formatting is incorrect');
        $this->assertEquals($expectedTotalVolume, $reportData['total_volume'], 'Total volume calculation is incorrect');
    }

    /**
     * Test if user can filter irrigation reports by labour.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_filter_irrigation_reports_by_labour(): void
    {
        $labour = Labour::where('farm_id', $this->farm->id)->first();

        $date = now(); // Use current date
        $jalaliDate = jdate($date)->format('Y/m/d');

        // Create some irrigations for the labour with specific duration
        $irrigation = Irrigation::factory()->create([
            'created_by' => $this->user->id,
            'labour_id' => $labour->id,
            'date' => $date->format('Y-m-d'),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'status' => 'finished',
        ]);

        $response = $this->postJson("/api/farms/{$this->farm->id}/irrigations/reports", [
            'labour_id' => $labour->id,
            'from_date' => $jalaliDate,
            'to_date' => $jalaliDate,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['date', 'total_duration', 'total_volume', 'irrigation_count']]]);
    }

    /**
     * Test if user can filter irrigation reports for the whole farm.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_filter_irrigation_reports_for_whole_farm(): void
    {
        $date = now(); // Use current date
        $jalaliDate = jdate($date)->format('Y/m/d');

        // Create some irrigations for the farm with specific duration
        $irrigation = Irrigation::factory()->for($this->farm)->create([
            'created_by' => $this->user->id,
            'date' => $date->format('Y-m-d'),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'status' => 'finished', // Added status 'finished'
        ]);

        $response = $this->postJson("/api/farms/{$this->farm->id}/irrigations/reports", [
            'from_date' => $jalaliDate,
            'to_date' => $jalaliDate,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['date', 'total_duration', 'total_volume', 'irrigation_count']]]);
    }
}
