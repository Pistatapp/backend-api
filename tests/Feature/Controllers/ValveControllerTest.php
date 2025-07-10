<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Plot;
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
    protected $field;
    protected $plot;

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

        $this->field = Field::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $this->plot = Plot::factory()->create([
            'field_id' => $this->field->id
        ]);
    }

    #[Test]
    public function user_can_list_valves()
    {
        $valves = Valve::factory()->count(3)->create([
            'plot_id' => $this->plot->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/plots/{$this->plot->id}/valves");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'plot_id',
                        'name',
                        'location',
                        'is_open',
                        'irrigation_area',
                        'dripper_count',
                        'dripper_flow_rate'
                    ]
                ]
            ]);
    }

    #[Test]
    public function user_can_create_valve()
    {
        $valveData = [
            'name' => 'Test Valve',
            'location' => [
                'lat' => 35.7219,
                'lng' => 51.3347
            ],
            'is_open' => false,
            'irrigation_area' => 2.5,
            'dripper_count' => 500,
            'dripper_flow_rate' => 4.5
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/plots/{$this->plot->id}/valves", $valveData);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Test Valve',
                'irrigation_area' => 2.5,
                'dripper_count' => 500,
                'dripper_flow_rate' => 4.5
            ]);

        $this->assertDatabaseHas('valves', [
            'name' => 'Test Valve',
            'plot_id' => $this->plot->id,
            'dripper_count' => 500
        ]);
    }

    #[Test]
    public function user_can_view_single_valve()
    {
        $valve = Valve::factory()->create([
            'plot_id' => $this->plot->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/valves/{$valve->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $valve->id,
                    'name' => $valve->name,
                    'plot_id' => $this->plot->id
                ]
            ]);
    }

    #[Test]
    public function user_can_update_valve()
    {
        $valve = Valve::factory()->create([
            'plot_id' => $this->plot->id
        ]);

        $updatedData = [
            'name' => 'Updated Valve Name',
            'location' => [
                'lat' => 35.7219,
                'lng' => 51.3347
            ],
            'is_open' => true,
            'irrigation_area' => 3.5,
            'dripper_count' => 600,
            'dripper_flow_rate' => 5.5
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/valves/{$valve->id}", $updatedData);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Valve Name',
                'irrigation_area' => 3.5,
                'dripper_count' => 600,
                'dripper_flow_rate' => 5.5
            ]);

        $this->assertDatabaseHas('valves', [
            'id' => $valve->id,
            'name' => 'Updated Valve Name',
            'dripper_count' => 600
        ]);
    }

    #[Test]
    public function user_can_delete_valve()
    {
        $valve = Valve::factory()->create([
            'plot_id' => $this->plot->id
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
            ->postJson("/api/plots/{$this->plot->id}/valves", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'location', 'irrigation_area', 'dripper_count', 'dripper_flow_rate']);
    }

    #[Test]
    public function validate_dripper_count_minimum()
    {
        $valveData = [
            'name' => 'Test Valve',
            'location' => [
                'lat' => 35.7219,
                'lng' => 51.3347
            ],
            'irrigation_area' => 2.5,
            'dripper_count' => -1,
            'dripper_flow_rate' => 4.5
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/plots/{$this->plot->id}/valves", $valveData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dripper_count']);
    }

    #[Test]
    public function validate_irrigation_area_minimum()
    {
        $valveData = [
            'name' => 'Test Valve',
            'location' => [
                'lat' => 35.7219,
                'lng' => 51.3347
            ],
            'irrigation_area' => -1,
            'dripper_count' => 500,
            'dripper_flow_rate' => 4.5
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/plots/{$this->plot->id}/valves", $valveData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['irrigation_area']);
    }
}
