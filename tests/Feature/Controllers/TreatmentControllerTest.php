<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Treatment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class TreatmentControllerTest extends TestCase
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
        $this->farm->users()->attach($this->user->id, [
            'role' => 'admin',
            'is_owner' => true,
        ]);
        $this->actingAs($this->user);
    }

    #[Test]
    public function user_can_get_list_of_treatments()
    {
        Treatment::factory()->count(3)->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->getJson("/api/farms/{$this->farm->id}/treatments");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'color',
                        'description'
                    ]
                ]
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    #[Test]
    public function user_can_create_treatment()
    {
        $response = $this->postJson("/api/farms/{$this->farm->id}/treatments", [
            'name' => 'Test Treatment',
            'color' => '#FF0000',
            'description' => 'Test description'
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'color',
                    'description'
                ]
            ]);

        $this->assertDatabaseHas('treatments', [
            'farm_id' => $this->farm->id,
            'name' => 'Test Treatment',
            'color' => '#FF0000',
            'description' => 'Test description'
        ]);
    }

    #[Test]
    public function user_cannot_create_treatment_with_duplicate_name()
    {
        Treatment::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Test Treatment'
        ]);

        $response = $this->postJson("/api/farms/{$this->farm->id}/treatments", [
            'name' => 'Test Treatment',
            'color' => '#FF0000',
            'description' => 'Test description'
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function user_can_view_treatment()
    {
        $treatment = Treatment::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->getJson("/api/treatments/{$treatment->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'color',
                    'description'
                ]
            ]);
    }

    #[Test]
    public function user_can_update_treatment()
    {
        $treatment = Treatment::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->putJson("/api/treatments/{$treatment->id}", [
            'name' => 'Updated Treatment',
            'color' => '#00FF00',
            'description' => 'Updated description'
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'color',
                    'description'
                ]
            ]);

        $this->assertDatabaseHas('treatments', [
            'id' => $treatment->id,
            'name' => 'Updated Treatment',
            'color' => '#00FF00',
            'description' => 'Updated description'
        ]);
    }

    #[Test]
    public function user_cannot_update_treatment_with_duplicate_name()
    {
        $treatment1 = Treatment::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Treatment 1'
        ]);

        $treatment2 = Treatment::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Treatment 2'
        ]);

        $response = $this->putJson("/api/treatments/{$treatment2->id}", [
            'name' => 'Treatment 1',
            'color' => '#00FF00',
            'description' => 'Updated description'
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function user_can_delete_treatment()
    {
        $treatment = Treatment::factory()->create([
            'farm_id' => $this->farm->id
        ]);

        $response = $this->deleteJson("/api/treatments/{$treatment->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('treatments', ['id' => $treatment->id]);
    }
}
