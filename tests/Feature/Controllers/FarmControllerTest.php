<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Crop;
use App\Models\Farm;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class FarmControllerTest extends TestCase
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
        $this->farm->users()->attach($this->user, [
            'role' => 'owner',
            'is_owner' => true
        ]);
        $this->actingAs($this->user);
    }

    #[Test]
    public function user_can_get_their_farms()
    {
        $response = $this->getJson('/api/farms');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'coordinates',
                        'center',
                        'zoom',
                        'area',
                        'trees_count',
                        'fields_count',
                        'labours_count',
                        'tractors_count',
                        'plans_count'
                    ]
                ]
            ]);
    }

    #[Test]
    public function user_can_create_farm()
    {
        $crop = Crop::factory()->create();

        $data = [
            'name' => 'Test Farm',
            'coordinates' => [
                '35.123,51.456',
                '35.124,51.457',
                '35.125,51.458',
                '35.123,51.456' // Closing the polygon
            ],
            'center' => '35.124,51.457',
            'zoom' => 15,
            'area' => 1000.50,
            'crop_id' => $crop->id
        ];

        $response = $this->postJson('/api/farms', $data);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'coordinates',
                    'center',
                    'zoom',
                    'area'
                ]
            ]);

        $this->assertDatabaseHas('farms', [
            'name' => 'Test Farm',
            'zoom' => 15,
            'area' => 1000.50,
            'crop_id' => $crop->id
        ]);
    }

    #[Test]
    public function user_can_view_single_farm()
    {
        $response = $this->getJson("/api/farms/{$this->farm->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'coordinates',
                    'center',
                    'zoom',
                    'area',
                    'crop',
                    'users',
                    'trees_count',
                    'fields_count',
                    'labours_count',
                    'tractors_count',
                    'plans_count'
                ]
            ]);
    }

    #[Test]
    public function user_can_update_farm()
    {
        $crop = Crop::factory()->create();

        $data = [
            'name' => 'Updated Farm',
            'coordinates' => [
                '35.123,51.456',
                '35.124,51.457',
                '35.125,51.458',
                '35.123,51.456' // Closing the polygon
            ],
            'center' => '35.124, 51.457',
            'zoom' => 16,
            'area' => 2000.75,
            'crop_id' => $crop->id
        ];

        $response = $this->putJson("/api/farms/{$this->farm->id}", $data);

        $response->assertOk();

        $this->assertDatabaseHas('farms', [
            'id' => $this->farm->id,
            'name' => 'Updated Farm',
            'zoom' => 16,
            'area' => 2000.75,
            'crop_id' => $crop->id
        ]);
    }

    #[Test]
    public function user_can_delete_farm()
    {
        $response = $this->deleteJson("/api/farms/{$this->farm->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('farms', ['id' => $this->farm->id]);
    }

    #[Test]
    public function user_can_set_farm_as_working_environment()
    {
        $response = $this->getJson("/api/farms/{$this->farm->id}/set_working_environment");

        $response->assertOk();

        $this->user->refresh();
        $this->assertEquals($this->farm->id, $this->user->preferences['working_environment']);
    }

    #[Test]
    public function owner_can_attach_user_to_farm()
    {
        $newUser = User::factory()->create();

        $response = $this->postJson("/api/farms/{$this->farm->id}/attach-user", [
            'user_id' => $newUser->id,
            'role' => 'operator'
        ]);

        $response->assertOk()
            ->assertJson(['message' => __('User attached to farm successfully.')]);

        $this->assertDatabaseHas('farm_user', [
            'farm_id' => $this->farm->id,
            'user_id' => $newUser->id,
            'role' => 'operator',
            'is_owner' => 0
        ]);
    }

    #[Test]
    public function owner_can_detach_user_from_farm()
    {
        $otherUser = User::factory()->create();
        $this->farm->users()->attach($otherUser, [
            'role' => 'operator',
            'is_owner' => false
        ]);

        $response = $this->postJson("/api/farms/{$this->farm->id}/detach-user", [
            'user_id' => $otherUser->id
        ]);

        $response->assertOk()
            ->assertJson(['message' => __('User detached from farm successfully.')]);

        $this->assertDatabaseMissing('farm_user', [
            'farm_id' => $this->farm->id,
            'user_id' => $otherUser->id
        ]);
    }

    #[Test]
    public function non_admin_cannot_attach_user_to_farm()
    {
        $regularUser = User::factory()->create();
        $regularUser->assignRole('operator');
        $this->actingAs($regularUser);

        $newUser = User::factory()->create();

        $response = $this->postJson("/api/farms/{$this->farm->id}/attach-user", [
            'user_id' => $newUser->id,
            'role' => 'operator'
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function cannot_attach_user_with_admin_role()
    {
        $newUser = User::factory()->create();

        $response = $this->postJson("/api/farms/{$this->farm->id}/attach-user", [
            'user_id' => $newUser->id,
            'role' => 'admin'
        ]);

        $response->assertUnprocessable();
    }
}
