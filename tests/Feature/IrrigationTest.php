<?php

namespace Tests\Feature;

use App\Models\Irrigation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Farm;
use App\Models\Labour;
use App\Models\Field;
use App\Models\Valve;
use App\Models\Pump;
use PHPUnit\Framework\Attributes\Test;

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

        $this->farm = Farm::factory()
            ->hasAttached($this->user, [
                'role' => 'admin',
                'is_owner' => true
            ])
            ->has(Field::factory()->count(3))
            ->has(Labour::factory())
            ->has(Pump::factory())
            ->create();

        $this->farm = $this->user->farms()->first();
        $this->fields = $this->farm->fields;
        $this->pump = $this->farm->pumps->first();

        $this->valves = Valve::factory(3)->create([
            'pump_id' => $this->farm->pumps->first()->id,
            'field_id' => $this->fields->first()->id
        ]);

        $this->actingAs($this->user);
    }

    /**
     * Test if user can create irrigation.
     */
    #[Test]
    public function test_user_can_create_irrigation(): void
    {
        $response = $this->postJson(route('farms.irrigations.store', ['farm' => $this->farm]), [
            'labour_id' => $this->farm->labours->first()->id,
            'date' => '1402/07/01',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'fields' =>  [1, 2],
            'valves' => [1, 2],
        ]);

        $this->assertDatabaseCount('irrigations', 1);

        $response->assertStatus(201);
    }

    /**
     * Test if user can update irrigation.
     */
    #[Test]
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
    #[Test]
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
    #[Test]
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
    #[Test]
    public function test_user_can_view_irrigations(): void
    {
        $response = $this->get(route('farms.irrigations.index', ['farm' => $this->farm]));

        $response->assertStatus(200);
    }

    /**
     * Test if user can get irrigations for a field.
     */
    #[Test]
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
