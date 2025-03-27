<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Crop;
use App\Models\CropType;
use App\Models\Field;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class CropTypeControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $rootUser;
    private Crop $crop;

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

        // Create a crop for testing
        $this->crop = Crop::factory()->create();
    }

    #[Test]
    public function user_can_view_crop_types_list()
    {
        CropType::factory()->count(3)->create([
            'crop_id' => $this->crop->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/crops/{$this->crop->id}/crop_types");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'standard_day_degree',
                        'created_at',
                        'can'
                    ]
                ]
            ]);
    }

    #[Test]
    public function non_root_user_cannot_create_crop_type()
    {
        $data = [
            'name' => 'Test Crop Type',
            'standard_day_degree' => 23.5
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/crops/{$this->crop->id}/crop_types", $data);

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_create_crop_type()
    {
        $data = [
            'name' => 'Test Crop Type',
            'standard_day_degree' => 23.5
        ];

        $response = $this->actingAs($this->rootUser)
            ->postJson("/api/crops/{$this->crop->id}/crop_types", $data);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'standard_day_degree',
                    'created_at'
                ]
            ]);

        $this->assertDatabaseHas('crop_types', [
            'name' => 'Test Crop Type',
            'crop_id' => $this->crop->id
        ]);
    }

    #[Test]
    public function validates_duplicate_crop_type_name()
    {
        CropType::factory()->create([
            'name' => 'Existing Type',
            'crop_id' => $this->crop->id
        ]);

        $response = $this->actingAs($this->rootUser)
            ->postJson("/api/crops/{$this->crop->id}/crop_types", [
                'name' => 'Existing Type',
                'standard_day_degree' => 23.5
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function non_root_user_cannot_update_crop_type()
    {
        $cropType = CropType::factory()->create([
            'name' => 'Original Name',
            'crop_id' => $this->crop->id
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'standard_day_degree' => 24.0
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/crop_types/{$cropType->id}", $updateData);

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_update_crop_type()
    {
        $cropType = CropType::factory()->create([
            'name' => 'Original Name',
            'crop_id' => $this->crop->id
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'standard_day_degree' => 24.0
        ];

        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/crop_types/{$cropType->id}", $updateData);

        $response->assertOk();
        $this->assertDatabaseHas('crop_types', [
            'id' => $cropType->id,
            'name' => 'Updated Name'
        ]);
    }

    #[Test]
    public function non_root_user_cannot_delete_crop_type()
    {
        $cropType = CropType::factory()->create([
            'crop_id' => $this->crop->id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/crop_types/{$cropType->id}");

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_delete_unused_crop_type()
    {
        $cropType = CropType::factory()->create([
            'crop_id' => $this->crop->id
        ]);

        $response = $this->actingAs($this->rootUser)
            ->deleteJson("/api/crop_types/{$cropType->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('crop_types', ['id' => $cropType->id]);
    }

    #[Test]
    public function cannot_delete_crop_type_with_fields()
    {
        $cropType = CropType::factory()->create([
            'crop_id' => $this->crop->id
        ]);

        Field::factory()->create([
            'crop_type_id' => $cropType->id
        ]);

        $response = $this->actingAs($this->rootUser)
            ->deleteJson("/api/crop_types/{$cropType->id}");

        $response->assertBadRequest();
        $this->assertDatabaseHas('crop_types', ['id' => $cropType->id]);
    }
}
