<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\Pump;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PumpControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $farm;

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
    }

    /** @test */
    public function user_can_list_pumps()
    {
        // Create some pumps
        $pumps = Pump::factory()->count(3)->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/pumps");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'farm_id',
                        'name',
                        'serial_number',
                        'model',
                        'manufacturer',
                        'horsepower',
                        'phase',
                        'voltage',
                        'ampere',
                        'rpm',
                        'pipe_size',
                        'debi',
                        'location',
                        'created_at'
                    ]
                ]
            ]);
    }

    /** @test */
    public function user_can_view_single_pump()
    {
        $pump = Pump::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/pumps/{$pump->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $pump->id,
                    'name' => $pump->name,
                    'farm_id' => $this->farm->id
                ]
            ]);
    }

    /** @test */
    public function user_can_create_pump()
    {
        $pumpData = [
            'name' => 'Test Pump',
            'serial_number' => 'SN123456',
            'model' => 'Model X',
            'manufacturer' => 'Manufacturer Inc',
            'horsepower' => 50,
            'phase' => 3,
            'voltage' => 220,
            'ampere' => 10,
            'rpm' => 1450,
            'pipe_size' => 4,
            'debi' => 100,
            'location' => 'North Side'
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/pumps", $pumpData);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Test Pump'
            ]);
    }

    /** @test */
    public function user_cannot_create_pump_with_duplicate_name_in_same_farm()
    {
        // Create first pump
        $pump = Pump::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Test Pump'
        ]);

        // Try to create another pump with the same name in the same farm
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/pumps", [
                'name' => 'Test Pump',
                'serial_number' => 'SN123456',
                'model' => 'Model X',
                'manufacturer' => 'Manufacturer Inc',
                'horsepower' => 50,
                'phase' => 3,
                'voltage' => 220,
                'ampere' => 10,
                'rpm' => 1450,
                'pipe_size' => 4,
                'debi' => 100,
                'location' => 'North Side'
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /** @test */
    public function user_can_create_pump_with_same_name_in_different_farm()
    {
        // Create first pump in first farm
        $pump = Pump::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Test Pump'
        ]);

        // Create another farm for the same user
        $anotherFarm = Farm::factory()->create();
        $this->user->farms()->attach($anotherFarm, [
            'is_owner' => true,
            'role' => 'admin'
        ]);

        // Try to create a pump with the same name in different farm
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$anotherFarm->id}/pumps", [
                'name' => 'Test Pump',
                'serial_number' => 'SN123456',
                'model' => 'Model X',
                'manufacturer' => 'Manufacturer Inc',
                'horsepower' => 50,
                'phase' => 3,
                'voltage' => 220,
                'ampere' => 10,
                'rpm' => 1450,
                'pipe_size' => 4,
                'debi' => 100,
                'location' => 'North Side'
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Test Pump'
            ]);
    }

    /** @test */
    public function user_can_update_pump()
    {
        $pump = Pump::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $updatedData = [
            'name' => 'Updated Pump Name',
            'serial_number' => $pump->serial_number,
            'model' => 'Updated Model',
            'manufacturer' => $pump->manufacturer,
            'horsepower' => 75,
            'phase' => $pump->phase,
            'voltage' => $pump->voltage,
            'ampere' => $pump->ampere,
            'rpm' => $pump->rpm,
            'pipe_size' => $pump->pipe_size,
            'debi' => $pump->debi,
            'location' => 'Updated Location'
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/pumps/{$pump->id}", $updatedData);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Pump Name',
                'model' => 'Updated Model',
                'horsepower' => 75,
                'location' => 'Updated Location'
            ]);
    }

    /** @test */
    public function user_cannot_update_pump_with_duplicate_name_in_same_farm()
    {
        // Create two pumps
        $pump1 = Pump::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Test Pump 1'
        ]);

        $pump2 = Pump::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Test Pump 2'
        ]);

        // Try to update pump2's name to pump1's name
        $response = $this->actingAs($this->user)
            ->putJson("/api/pumps/{$pump2->id}", [
                'name' => 'Test Pump 1',
                'serial_number' => $pump2->serial_number,
                'model' => $pump2->model,
                'manufacturer' => $pump2->manufacturer,
                'horsepower' => $pump2->horsepower,
                'phase' => $pump2->phase,
                'voltage' => $pump2->voltage,
                'ampere' => $pump2->ampere,
                'rpm' => $pump2->rpm,
                'pipe_size' => $pump2->pipe_size,
                'debi' => $pump2->debi,
                'location' => $pump2->location
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /** @test */
    public function user_can_delete_pump()
    {
        $pump = Pump::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/pumps/{$pump->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('pumps', ['id' => $pump->id]);
    }
}
