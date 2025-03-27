<?php

namespace Tests\Feature\Controllers;

use App\Models\Pest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PestControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $rootUser;
    private User $normalUser;
    private Pest $pest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // Create users
        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');

        $this->normalUser = User::factory()->create();

        // Create a test pest
        $this->pest = Pest::factory()->create();
    }

    #[Test]
    public function normal_user_can_view_pests_list()
    {
        $response = $this->actingAs($this->normalUser)
            ->getJson('/api/pests');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    #[Test]
    public function normal_user_can_view_single_pest()
    {
        $response = $this->actingAs($this->normalUser)
            ->getJson("/api/pests/{$this->pest->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'can']
            ]);
    }

    #[Test]
    public function normal_user_cannot_create_pest()
    {
        $response = $this->actingAs($this->normalUser)
            ->postJson('/api/pests', [
                'name' => 'Test Pest',
                'description' => 'Test Description'
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_create_pest()
    {
        $response = $this->actingAs($this->rootUser)
            ->postJson('/api/pests', [
                'name' => 'Test Pest',
                'description' => 'Test Description'
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'can']
            ]);
    }

    #[Test]
    public function normal_user_cannot_update_pest()
    {
        $response = $this->actingAs($this->normalUser)
            ->putJson("/api/pests/{$this->pest->id}", [
                'name' => 'Updated Name'
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_update_pest()
    {
        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/pests/{$this->pest->id}", [
                'name' => 'Updated Name'
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    #[Test]
    public function normal_user_cannot_delete_pest()
    {
        $response = $this->actingAs($this->normalUser)
            ->deleteJson("/api/pests/{$this->pest->id}");

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_delete_pest()
    {
        $response = $this->actingAs($this->rootUser)
            ->deleteJson("/api/pests/{$this->pest->id}");

        $response->assertNoContent();
    }

    #[Test]
    public function root_user_can_delete_pest_image()
    {
        $response = $this->actingAs($this->rootUser)
            ->deleteJson("/api/pests/{$this->pest->id}/image");

        $response->assertNoContent();
    }

    #[Test]
    public function normal_user_cannot_delete_pest_image()
    {
        $response = $this->actingAs($this->normalUser)
            ->deleteJson("/api/pests/{$this->pest->id}/image");

        $response->assertForbidden();
    }
}
