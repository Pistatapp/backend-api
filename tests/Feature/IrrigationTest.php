<?php

namespace Tests\Feature;

use App\Models\Irrigation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class IrrigationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Indicates if the database should be seeded.
     *
     * @var bool
     */
    protected $seed = true;

    /**
     * Test if user can create irrigation.
     */
    public function test_user_can_create_irrigation(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->with('labours')->first();

        $response = $this->actingAs($user)->post(route('farms.irrigations.store', ['farm' => $farm]), [
            'labour_id' => $farm->labours->first()->id,
            'date' => '1402/07/01',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'fields' => [1, 2],
            'valves' => [1, 2],
        ]);

        $this->assertDatabaseCount('irrigations', 1);

        $response->assertStatus(201);
    }

    /**
     * Test if user can update irrigation.
     */
    public function test_user_can_update_irrigation(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->with('labours')->first();
        $irrigation = Irrigation::factory()->for($farm)->create([
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->put(route('irrigations.update', $irrigation), [
            'labour_id' => $farm->labours->first()->id,
            'date' => '1402/07/01',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'fields' => [1, 2],
            'valves' => [1, 2],
        ]);

        $this->assertDatabaseCount('irrigations', 1);

        $response->assertStatus(200);
    }

    /**
     * Test if user can delete irrigation.
     */
    public function test_user_can_delete_irrigation(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->first();
        $irrigation = Irrigation::factory()->create([
            'farm_id' => $farm->id,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->delete(route('irrigations.destroy', $irrigation));

        $this->assertDatabaseCount('irrigations', 0);

        $response->assertStatus(204);
    }

    /**
     * Test if user can view irrigation.
     */
    public function test_user_can_view_irrigation(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->with('labours')->first();
        $irrigation = Irrigation::factory()->create([
            'farm_id' => $farm->id,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('irrigations.show', $irrigation));

        $response->assertStatus(200);
    }

    /**
     * Test if user can view irrigations.
     */
    public function test_user_can_view_irrigations(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->with('labours')->first();

        $response = $this->actingAs($user)->get(route('farms.irrigations.index', ['farm' => $farm]));

        $response->assertStatus(200);
    }

    /**
     * Test if user can get irrigations for a field.
     */
    public function test_user_can_get_irrigations_for_field(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->with('fields')->first();
        $field = $farm->fields->first();

        // Create some irrigations for the field
        Irrigation::factory()->hasAttached($field)->count(3)->create([
            'created_by' => $user->id,
            'date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($user)->getJson(route('fields.irrigations', ['field' => $field]));

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    /**
     * Test if user can get irrigation report for a field.
     */
    public function test_user_can_get_irrigation_report_for_field(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->with('fields')->first();
        $field = $farm->fields->first();

        // Create some irrigations for the field
        Irrigation::factory()->hasAttached($field)->count(3)->create([
            'created_by' => $user->id,
            'date' => now()->format('Y-m-d'),
            'start_time' => now()->subHours(2),
            'end_time' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('fields.irrigations.report', ['field' => $field]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'date',
                'total_duration',
                'total_volume',
                'irrigation_count',
            ],
        ]);
    }

    /**
     * Test if user can filter irrigation reports by field.
     */
    public function test_user_can_filter_irrigation_reports_by_field(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->with('fields')->first();
        $field = $farm->fields->first();

        $date = now(); // Use current date
        $jalaliDate = jdate($date)->format('Y/m/d');

        // Create some irrigations for the field with specific duration
        $irrigation = Irrigation::factory()->hasAttached($field)->create([
            'created_by' => $user->id,
            'date' => $date->format('Y-m-d'),
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        $response = $this->actingAs($user)->postJson(route('irrigations.reports.filter', [
            'farm' => $farm,
            'field_id' => $field->id,
            'from_date' => $jalaliDate,
            'to_date' => $jalaliDate,
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['date', 'total_duration', 'total_volume', 'irrigation_count']]]);
    }

    /**
     * Test if user can filter irrigation reports by valve.
     */
    public function test_user_can_filter_irrigation_reports_by_valve(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->with('valves')->first();
        $valve = $farm->valves->first();

        $date = now(); // Use current date
        $jalaliDate = jdate($date)->format('Y/m/d');

        // Create some irrigations for the valve with specific duration
        $irrigation = Irrigation::factory()->hasAttached($valve)->create([
            'created_by' => $user->id,
            'date' => $date->format('Y-m-d'),
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        $response = $this->actingAs($user)->postJson(route('irrigations.reports.filter', [
            'farm' => $farm,
            'valve_id' => $valve->id,
            'from_date' => $jalaliDate,
            'to_date' => $jalaliDate,
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['date', 'total_duration', 'total_volume', 'irrigation_count']]]);
    }

    /**
     * Test if user can filter irrigation reports by labour.
     */
    public function test_user_can_filter_irrigation_reports_by_labour(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->with('labours')->first();
        $labour = $farm->labours->first();

        $date = now(); // Use current date
        $jalaliDate = jdate($date)->format('Y/m/d');

        // Create some irrigations for the labour with specific duration
        $irrigation = Irrigation::factory()->create([
            'created_by' => $user->id,
            'labour_id' => $labour->id,
            'date' => $date->format('Y-m-d'),
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        $response = $this->actingAs($user)->postJson(route('irrigations.reports.filter', [
            'farm' => $farm,
            'labour_id' => $labour->id,
            'from_date' => $jalaliDate,
            'to_date' => $jalaliDate,
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['date', 'total_duration', 'total_volume', 'irrigation_count']]]);
    }

    /**
     * Test if user can filter irrigation reports for the whole farm.
     */
    public function test_user_can_filter_irrigation_reports_for_whole_farm(): void
    {
        $user = User::where('mobile', '09369238614')->first();
        $farm = $user->farms()->first();

        $date = now(); // Use current date
        $jalaliDate = jdate($date)->format('Y/m/d');

        // Create some irrigations for the farm with specific duration
        $irrigation = Irrigation::factory()->for($farm)->create([
            'created_by' => $user->id,
            'date' => $date->format('Y-m-d'),
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        $response = $this->actingAs($user)->postJson(route('irrigations.reports.filter', [
            'farm' => $farm,
            'from_date' => $jalaliDate,
            'to_date' => $jalaliDate,
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['date', 'total_duration', 'total_volume', 'irrigation_count']]]);
    }
}
