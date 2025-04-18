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
 * Test class for basic irrigation CRUD operations
 */
class IrrigationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private $tractor;
    private $farm;
    private $fields;
    private $valves;
    private $pump;

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
     * Test if user can create irrigation.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_create_irrigation(): void
    {
        $response = $this->postJson(route('farms.irrigations.store', ['farm' => $this->farm]), [
            'labour_id' => $this->farm->labours->first()->id,
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
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_update_irrigation(): void
    {
        $irrigation = Irrigation::factory()->for($this->farm)->create([
            'created_by' => $this->user->id,
        ]);

        $response = $this->putJson(route('irrigations.update', $irrigation), [
            'labour_id' => $this->farm->labours->first()->id,
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
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_delete_irrigation(): void
    {
        $irrigation = Irrigation::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteJson(route('irrigations.destroy', $irrigation));

        $this->assertDatabaseCount('irrigations', 0);

        $response->assertStatus(204);
    }

    /**
     * Test if user can view irrigation.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_view_irrigation(): void
    {
        $irrigation = Irrigation::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->get(route('irrigations.show', $irrigation));

        $response->assertStatus(200);
    }

    /**
     * Test if user can view irrigations.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_view_irrigations(): void
    {
        $response = $this->get(route('farms.irrigations.index', ['farm' => $this->farm]));

        $response->assertStatus(200);
    }

    /**
     * Test if user can get irrigations for a field.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_user_can_get_irrigations_for_field(): void
    {
        $field = $this->fields->first();

        // Create some irrigations for the field
        Irrigation::factory()->hasAttached($field)->count(3)->create([
            'created_by' => $this->user->id,
            'date' => now()->format('Y-m-d'),
        ]);

        $response = $this->getJson('api/fields/' . $field->id . '/irrigations');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }
}
