<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Pump;
use App\Models\User;
use App\Models\Valve;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ValveControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $farm;
    protected $pump;
    protected $field;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->user->assignRole('admin');

        $this->farm = Farm::factory()->create();
        $this->user->farms()->attach($this->farm, [
            'is_owner' => true,
            'role' => 'admin'
        ]);

        $this->pump = Pump::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $this->field = Field::factory()->create([
            'farm_id' => $this->farm->id
        ]);
    }

    #[Test]
    public function user_can_list_valves()
    {
        $valves = Valve::factory()->count(3)->create([
            'pump_id' => $this->pump->id,
            'field_id' => $this->field->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/pumps/{$this->pump->id}/valves");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'pump_id',
                        'name',
                        'location',
                        'flow_rate',
                        'field_id',
                        'is_open',
                        'irrigated_area'
                    ]
                ]
            ]);
    }

    #[Test]
    public function user_can_create_valve()
    {
        $valveData = [
            'name' => 'Test Valve',
            'location' => '35.7219,51.3347',
            'flow_rate' => 50,
            'field_id' => $this->field->id,
            'irrigated_area' => 2.5
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/pumps/{$this->pump->id}/valves", $valveData);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Test Valve',
                'flow_rate' => 50,
                'irrigated_area' => 2.5
            ]);

        $this->assertDatabaseHas('valves', [
            'name' => 'Test Valve',
            'pump_id' => $this->pump->id
        ]);
    }

    #[Test]
    public function user_can_view_single_valve()
    {
        $valve = Valve::factory()->create([
            'pump_id' => $this->pump->id,
            'field_id' => $this->field->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/valves/{$valve->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $valve->id,
                    'name' => $valve->name
                ]
            ]);
    }

    #[Test]
    public function user_can_update_valve()
    {
        $valve = Valve::factory()->create([
            'pump_id' => $this->pump->id,
            'field_id' => $this->field->id
        ]);

        $updatedData = [
            'name' => 'Updated Valve Name',
            'location' => '35.7219,51.3347',
            'flow_rate' => 75,
            'field_id' => $this->field->id,
            'irrigated_area' => 3.5
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/valves/{$valve->id}", $updatedData);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Valve Name',
                'flow_rate' => 75,
                'irrigated_area' => 3.5
            ]);

        $this->assertDatabaseHas('valves', [
            'id' => $valve->id,
            'name' => 'Updated Valve Name'
        ]);
    }

    #[Test]
    public function user_can_delete_valve()
    {
        $valve = Valve::factory()->create([
            'pump_id' => $this->pump->id,
            'field_id' => $this->field->id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/valves/{$valve->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('valves', ['id' => $valve->id]);
    }

    #[Test]
    public function validate_required_fields_when_creating_valve()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/pumps/{$this->pump->id}/valves", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'location', 'flow_rate', 'field_id', 'irrigated_area']);
    }

    #[Test]
    public function validate_flow_rate_range()
    {
        $valveData = [
            'name' => 'Test Valve',
            'location' => '35.7219,51.3347',
            'flow_rate' => 101,
            'field_id' => $this->field->id
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/pumps/{$this->pump->id}/valves", $valveData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['flow_rate']);
    }

    #[Test]
    public function validate_irrigated_area_range()
    {
        $valveData = [
            'name' => 'Test Valve',
            'location' => '35.7219,51.3347',
            'flow_rate' => 50,
            'field_id' => $this->field->id,
            'irrigated_area' => -1 // Invalid negative value
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/pumps/{$this->pump->id}/valves", $valveData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['irrigated_area']);

        // Test non-numeric value
        $valveData['irrigated_area'] = 'not-a-number';
        $response = $this->actingAs($this->user)
            ->postJson("/api/pumps/{$this->pump->id}/valves", $valveData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['irrigated_area']);
    }
}
