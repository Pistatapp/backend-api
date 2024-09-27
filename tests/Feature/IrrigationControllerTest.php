<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Field;
use App\Models\Labour;
use App\Models\Valve;
use App\Models\Irrigation;
use App\Models\Pump;

class IrrigationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user can create irrigation.
     *
     * @return void
     */
    public function test_user_can_view_irrigations()
    {
        $user = User::factory()->create();
        $farm = Farm::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/farms/' . $farm->id . '/irrigations');
        $response->assertStatus(200);
    }

    /**
     * Test user can create irrigation.
     *
     * @return void
     */
    public function test_user_can_create_irrigation()
    {
        $user = User::factory()->create();

        $farm = Farm::factory()
            ->has(Field::factory()->count(3))
            ->create([
                'user_id' => $user->id,
            ]);
        $pump = Pump::factory()
            ->has(Valve::factory()->count(3))
            ->create([
                'farm_id' => $farm->id,
            ]);
        $labour = Labour::factory()->create([
            'farm_id' => $farm->id,
        ]);

        $valves = $pump->valves->pluck('id')->toArray();
        $fields = $farm->fields->pluck('id')->toArray();

        $response = $this->actingAs($user, 'api')->postJson('/api/farms/' . $farm->id . '/irrigations', [
            'labour_id' => $labour->id,
            'date' => '1400/01/01',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'fields' => $fields,
            'valves' => $valves,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('irrigations', [
            'farm_id' => $farm->id,
            'labour_id' => $labour->id,
            'date' => jalali_to_carbon('1400/01/01'),
        ]);
    }

    /**
     * Test user can update irrigation.
     *
     * @return void
     */
    public function test_user_can_update_irrigation()
    {
        $user = User::factory()->create();

        $farm = Farm::factory()
            ->has(Field::factory()->count(3))
            ->create([
                'user_id' => $user->id,
            ]);
        $pump = Pump::factory()
            ->has(Valve::factory()->count(3))
            ->create([
                'farm_id' => $farm->id,
            ]);

        $labour = Labour::factory()->create([
            'farm_id' => $farm->id,
        ]);

        $irrigation = Irrigation::factory()->create([
            'farm_id' => $farm->id,
            'date' => '1400/07/01',
            'created_by' => $user->id,
        ]);

        $valves = $pump->valves->pluck('id')->toArray();
        $fields = $farm->fields->pluck('id')->toArray();

        $response = $this->actingAs($user)->putJson('/api/irrigations/' . $irrigation->id, [
            'labour_id' => $labour->id,
            'date' => '1400/07/01',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'fields' => $fields,
            'valves' => $valves,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('irrigations', [
            'id' => $irrigation->id,
            'date' => jalali_to_carbon('1400/07/01'),
        ]);
    }

    /**
     * Test user can delete irrigation.
     *
     * @return void
     */
    public function test_user_can_delete_irrigation()
    {
        $user = User::factory()->create();

        $farm = Farm::factory()
            ->has(Field::factory()->count(3))
            ->create([
                'user_id' => $user->id,
            ]);

        $irrigation = Irrigation::factory()->create([
            'farm_id' => $farm->id,
            'date' => '1400/07/01',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->deleteJson('/api/irrigations/' . $irrigation->id);
        $response->assertStatus(204);

        $this->assertDatabaseMissing('irrigations', [
            'id' => $irrigation->id,
        ]);
    }

    /**
     * Test user can view irrigation.
     *
     * @return void
     */
    public function test_irrigation_status_changes_to_in_progress()
    {

        $irrigation = Irrigation::factory()->create([
            'status' => 'pending',
            'date' => now(),
            'start_time' => now()->subMinute(),
        ]);

        $this->artisan('app:change-irrigation-status');

        $this->assertDatabaseHas('irrigations', [
            'id' => $irrigation->id,
            'status' => 'in-progress',
        ]);
    }

    /**
     * Test irrigation status changes to completed.
     *
     * @return void
     */
    public function test_irrigation_status_changes_to_completed()
    {
        $irrigation = Irrigation::factory()
            ->create([
                'status' => 'in-progress',
                'date' => now(),
                'end_time' => now()->subMinute(),
            ]);

        $this->artisan('app:change-irrigation-status');

        $this->assertDatabaseHas('irrigations', [
            'id' => $irrigation->id,
            'status' => 'completed',
        ]);
    }

    /**
     * Test irrigation duration.
     *
     * @return void
     */
    public function test_irrigation_duration()
    {
        $irrigation = Irrigation::factory()->create([
            'start_time' => '01:00',
            'end_time' => '02:00',
        ]);

        $this->assertEquals('01:00', $irrigation->duration);
    }

    /**
     * Test each field irrigation time does not overlap.
     *
     * @return void
     */
    public function test_field_irrigation_time_does_not_overlap()
    {
        $user = User::factory()->create();

        $farm = Farm::factory()
            ->has(Field::factory()->count(3))
            ->create([
                'user_id' => $user->id,
            ]);

        $labour = Labour::factory()->create([
            'farm_id' => $farm->id,
        ]);

        $valves = Valve::factory()->for(Pump::factory()->create([
            'farm_id' => $farm->id,
        ]))->count(3)->create();

        $irrigation = Irrigation::factory()->create([
            'farm_id' => $farm->id,
            'date' => '1400/07/04',
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        $irrigation->fields()->attach($farm->fields);
        $irrigation->valves()->attach($valves);

        $response = $this->actingAs($user)->postJson('/api/farms/' . $farm->id . '/irrigations', [
            'labour_id' => $labour->id,
            'date' => '1400/07/04',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'fields' => $farm->fields->pluck('id')->toArray(),
            'valves' => $valves->pluck('id')->toArray(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fields']);
    }

    /**
     * Test each valve irrigation time does not overlap.
     *
     * @return void
     */
    public function test_valve_irrigation_time_does_not_overlap()
    {
        $user = User::factory()->create();

        $farm = Farm::factory()
            ->has(Field::factory()->count(3))
            ->create([
                'user_id' => $user->id,
            ]);

        $labour = Labour::factory()->create([
            'farm_id' => $farm->id,
        ]);

        $irrigation = Irrigation::factory()->create([
            'farm_id' => $farm->id,
            'date' => '1400/07/01',
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        $valves = Valve::factory()->for(Pump::factory()->create([
            'farm_id' => $farm->id,
        ]))->count(3)->create();

        $irrigation->valves()->attach($valves);
        $irrigation->fields()->attach($farm->fields);

        $response = $this->actingAs($user)->postJson('/api/farms/' . $farm->id . '/irrigations', [
            'labour_id' => $labour->id,
            'date' => '1400/07/01',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'fields' => $farm->fields->pluck('id')->toArray(),
            'valves' => $valves->pluck('id')->toArray(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['valves']);
    }

    /**
     * Test user can view irrigations for specific field.
     *
     * @return void
     */
    public function test_user_can_view_irrigations_for_specific_field()
    {
        $user = User::factory()->create();

        $farm = Farm::factory()
            ->has(Field::factory()->count(3))
            ->create([
                'user_id' => $user->id,
            ]);

        $field = $farm->fields->first();

        $response = $this->actingAs($user)->getJson('/api/fields/' . $field->id . '/irrigations');
        $response->assertStatus(200);
    }

    /**
     * Test user can view irrigations for specific field with spcicified date in the query string
     *
     * @return void
     */
    public function test_user_can_view_irrigations_for_specific_field_with_specified_date()
    {
        $user = User::factory()->create();

        $farm = Farm::factory()
            ->has(Field::factory()->count(3))
            ->create([
                'user_id' => $user->id,
            ]);

        $field = $farm->fields->first();

        Irrigation::factory()->create([
            'farm_id' => $farm->id,
            'date' => '1400/07/01',
        ]);

        $response = $this->actingAs($user)->getJson('/api/fields/' . $field->id . '/irrigations?date=1400/07/01');
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'labour',
                    'date',
                    'start_time',
                    'end_time',
                    'valves',
                    'fields',
                    'created_by',
                    'note',
                    'status',
                    'created_at',
                ],
            ],
        ]);
    }
}
