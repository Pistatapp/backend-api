<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Maintenance;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MaintenanceControllerTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $this->user->assignRole('admin');
        $this->farm = Farm::factory()->create();
        $this->user->farms()->attach($this->farm->id, [
            'role' => 'admin',
            'is_owner' => true,
        ]);
        $this->actingAs($this->user);
    }

    /** @test */
    public function user_can_get_list_of_maintenances()
    {
        // Create some test maintenances
        Maintenance::factory()->count(3)->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->getJson("/api/farms/{$this->farm->id}/maintenances");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name'
                    ]
                ]
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    /** @test */
    public function user_can_search_maintenances()
    {
        // Create test maintenances with specific names
        Maintenance::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Oil Change'
        ]);
        Maintenance::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Tire Rotation'
        ]);

        $response = $this->getJson("/api/farms/{$this->farm->id}/maintenances?search=Oil");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Oil Change', $response->json('data.0.name'));
    }

    /** @test */
    public function user_can_create_maintenance()
    {
        $response = $this->postJson("/api/farms/{$this->farm->id}/maintenances", [
            'name' => 'New Maintenance Task'
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name'
                ]
            ]);

        $this->assertDatabaseHas('maintenances', [
            'farm_id' => $this->farm->id,
            'name' => 'New Maintenance Task'
        ]);
    }

    /** @test */
    public function user_cannot_create_maintenance_without_name()
    {
        $response = $this->postJson("/api/farms/{$this->farm->id}/maintenances", [
            'name' => ''
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    /** @test */
    public function user_can_update_maintenance()
    {
        $maintenance = Maintenance::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Old Name'
        ]);

        $response = $this->putJson("/api/maintenances/{$maintenance->id}", [
            'name' => 'Updated Name'
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('maintenances', [
            'id' => $maintenance->id,
            'name' => 'Updated Name'
        ]);
    }

    /** @test */
    public function user_cannot_update_maintenance_with_empty_name()
    {
        $maintenance = Maintenance::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->putJson("/api/maintenances/{$maintenance->id}", [
            'name' => ''
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    /** @test */
    public function user_can_delete_maintenance()
    {
        $maintenance = Maintenance::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->deleteJson("/api/maintenances/{$maintenance->id}");

        $response->assertStatus(410);
        $this->assertDatabaseMissing('maintenances', ['id' => $maintenance->id]);
    }
}
