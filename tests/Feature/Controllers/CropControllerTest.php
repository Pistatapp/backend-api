<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Crop;
use App\Models\Farm;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class CropControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $rootUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // Create regular user
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        // Create root user
        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');
    }

    #[Test]
    public function regular_users_can_view_crops_list()
    {
        Crop::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/crops');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'cold_requirement',
                        'created_at',
                        'can'
                    ]
                ]
            ]);
    }

    #[Test]
    public function regular_users_can_view_crop_details()
    {
        $crop = Crop::factory()->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/crops/{$crop->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'cold_requirement',
                    'created_at',
                    'can',
                    'crop_types'
                ]
            ]);
    }

    #[Test]
    public function only_root_can_create_crop()
    {
        $cropData = [
            'name' => 'Test Crop',
            'cold_requirement' => 100
        ];

        // Regular user cannot create
        $response = $this->actingAs($this->user)
            ->postJson('/api/crops', $cropData);

        $response->assertForbidden();

        // Root user can create
        $response = $this->actingAs($this->rootUser)
            ->postJson('/api/crops', $cropData);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'cold_requirement',
                    'created_at'
                ]
            ]);

        $this->assertDatabaseHas('crops', [
            'name' => 'Test Crop',
            'cold_requirement' => 100
        ]);
    }

    #[Test]
    public function validates_duplicate_crop_name_on_create()
    {
        Crop::factory()->create(['name' => 'Existing Crop']);

        $response = $this->actingAs($this->rootUser)
            ->postJson('/api/crops', [
                'name' => 'Existing Crop',
                'cold_requirement' => 100
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function only_root_can_update_crop()
    {
        $crop = Crop::factory()->create([
            'name' => 'Original Name',
            'cold_requirement' => 50
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'cold_requirement' => 150
        ];

        // Regular user cannot update
        $response = $this->actingAs($this->user)
            ->putJson("/api/crops/{$crop->id}", $updateData);

        $response->assertForbidden();

        // Root user can update
        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/crops/{$crop->id}", $updateData);

        $response->assertOk();
        $this->assertDatabaseHas('crops', [
            'id' => $crop->id,
            'name' => 'Updated Name',
            'cold_requirement' => 150
        ]);
    }

    #[Test]
    public function validates_duplicate_crop_name_on_update()
    {
        $crop1 = Crop::factory()->create(['name' => 'Crop 1']);
        $crop2 = Crop::factory()->create(['name' => 'Crop 2']);

        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/crops/{$crop2->id}", [
                'name' => 'Crop 1',
                'cold_requirement' => 100
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function only_root_can_delete_unused_crop()
    {
        $crop = Crop::factory()->create();

        // Regular user cannot delete
        $response = $this->actingAs($this->user)
            ->deleteJson("/api/crops/{$crop->id}");

        $response->assertForbidden();

        // Root user can delete unused crop
        $response = $this->actingAs($this->rootUser)
            ->deleteJson("/api/crops/{$crop->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('crops', ['id' => $crop->id]);
    }

    #[Test]
    public function cannot_delete_crop_with_associated_farms()
    {
        $crop = Crop::factory()->create();
        Farm::factory()->create(['crop_id' => $crop->id]);

        $response = $this->actingAs($this->rootUser)
            ->deleteJson("/api/crops/{$crop->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('crops', ['id' => $crop->id]);
    }
}
