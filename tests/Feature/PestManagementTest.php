<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Pest;
use Spatie\Permission\Models\Role;

class PestManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $rootUser;
    protected $adminUser;
    protected $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        Role::create(['name' => 'root']);
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'operator']);

        // Create users
        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');

        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('admin');

        $this->regularUser = User::factory()->create();
        $this->regularUser->assignRole('operator');
    }

    /** @test */
    public function root_user_can_create_global_pests()
    {
        $pestData = [
            'name' => 'Test Global Pest',
            'scientific_name' => 'Testus Pestus',
            'description' => 'A test pest created by root',
            'damage' => 'Test damage',
            'management' => 'Test management',
            'standard_day_degree' => 25.5,
        ];

        $response = $this->actingAs($this->rootUser)
            ->postJson('/api/pests', $pestData);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'name' => 'Test Global Pest',
            'is_global' => true,
            'is_owned' => false,
        ]);

        $this->assertDatabaseHas('pests', [
            'name' => 'Test Global Pest',
            'created_by' => null,
        ]);
    }

    /** @test */
    public function admin_user_can_create_their_own_pests()
    {
        $pestData = [
            'name' => 'Test Admin Pest',
            'scientific_name' => 'Testus Adminus',
            'description' => 'A test pest created by admin',
            'damage' => 'Test damage',
            'management' => 'Test management',
            'standard_day_degree' => 22.0,
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/pests', $pestData);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'name' => 'Test Admin Pest',
            'is_global' => false,
            'is_owned' => true,
        ]);

        $this->assertDatabaseHas('pests', [
            'name' => 'Test Admin Pest',
            'created_by' => $this->adminUser->id,
        ]);
    }

    /** @test */
    public function regular_user_cannot_create_pests()
    {
        $pestData = [
            'name' => 'Test Regular Pest',
            'scientific_name' => 'Testus Regularus',
        ];

        $response = $this->actingAs($this->regularUser)
            ->postJson('/api/pests', $pestData);

        $response->assertStatus(403);
    }

    /** @test */
    public function root_user_can_only_see_global_pests()
    {
        // Create a global pest (by root)
        $globalPest = Pest::create([
            'name' => 'Global Pest',
            'created_by' => null,
        ]);

        // Create an admin pest
        $adminPest = Pest::create([
            'name' => 'Admin Pest',
            'created_by' => $this->adminUser->id,
        ]);

        // Root user should only see global pests
        $response = $this->actingAs($this->rootUser)
            ->getJson('/api/pests');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Global Pest']);
        $response->assertJsonMissing(['name' => 'Admin Pest']);
    }

    /** @test */
    public function admin_user_can_see_global_and_own_pests()
    {
        // Create a global pest (by root)
        $globalPest = Pest::create([
            'name' => 'Global Pest',
            'created_by' => null,
        ]);

        // Create an admin pest
        $adminPest = Pest::create([
            'name' => 'Admin Pest',
            'created_by' => $this->adminUser->id,
        ]);

        // Create another admin's pest
        $anotherAdmin = User::factory()->create();
        $anotherAdmin->assignRole('admin');
        $anotherAdminPest = Pest::create([
            'name' => 'Another Admin Pest',
            'created_by' => $anotherAdmin->id,
        ]);

        // Admin user should see global pests and their own pests
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/pests');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['name' => 'Global Pest']);
        $response->assertJsonFragment(['name' => 'Admin Pest']);
        $response->assertJsonMissing(['name' => 'Another Admin Pest']);
    }

    /** @test */
    public function regular_user_can_see_only_global_pests()
    {
        // Create a global pest (by root)
        $globalPest = Pest::create([
            'name' => 'Global Pest',
            'created_by' => null,
        ]);

        // Create an admin pest
        $adminPest = Pest::create([
            'name' => 'Admin Pest',
            'created_by' => $this->adminUser->id,
        ]);

        // Regular user should see only global pests
        $response = $this->actingAs($this->regularUser)
            ->getJson('/api/pests');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Global Pest']);
    }

    /** @test */
    public function admin_user_can_update_their_own_pests()
    {
        // Create a pest owned by admin
        $adminPest = Pest::create([
            'name' => 'Admin Pest',
            'created_by' => $this->adminUser->id,
        ]);

        // Admin can update their own pest
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/pests/{$adminPest->id}", [
                'name' => 'Updated Admin Pest',
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Updated Admin Pest']);
    }

    /** @test */
    public function admin_user_cannot_update_global_pests()
    {
        // Create a global pest
        $globalPest = Pest::create([
            'name' => 'Global Pest',
            'created_by' => null,
        ]);

        // Admin cannot update global pest
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/pests/{$globalPest->id}", [
                'name' => 'Updated Global Pest',
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function admin_user_can_delete_their_own_pests()
    {
        // Create a pest owned by admin
        $adminPest = Pest::create([
            'name' => 'Admin Pest',
            'created_by' => $this->adminUser->id,
        ]);

        // Admin can delete their own pest
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/pests/{$adminPest->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('pests', ['id' => $adminPest->id]);
    }

    /** @test */
    public function admin_user_cannot_delete_global_pests()
    {
        // Create a global pest
        $globalPest = Pest::create([
            'name' => 'Global Pest',
            'created_by' => null,
        ]);

        // Admin cannot delete global pest
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/pests/{$globalPest->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function root_user_can_update_global_pests()
    {
        // Create a global pest
        $globalPest = Pest::create([
            'name' => 'Global Pest',
            'created_by' => null,
        ]);

        // Root can update global pest
        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/pests/{$globalPest->id}", [
                'name' => 'Updated by Root',
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Updated by Root']);
    }

    /** @test */
    public function root_user_can_delete_global_pests()
    {
        // Create a global pest
        $globalPest = Pest::create([
            'name' => 'Global Pest',
            'created_by' => null,
        ]);

        // Root can delete global pest
        $response = $this->actingAs($this->rootUser)
            ->deleteJson("/api/pests/{$globalPest->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('pests', ['id' => $globalPest->id]);
    }

    /** @test */
    public function root_user_cannot_access_user_specific_pests()
    {
        // Create a pest owned by admin
        $adminPest = Pest::create([
            'name' => 'Admin Pest',
            'created_by' => $this->adminUser->id,
        ]);

        // Root cannot update admin's pest
        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/pests/{$adminPest->id}", [
                'name' => 'Updated by Root',
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function pest_names_must_be_globally_unique()
    {
        // Create a global pest
        Pest::create([
            'name' => 'Global Pest',
            'created_by' => null,
        ]);

        // Admin cannot create pest with same name (globally unique)
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/pests', [
                'name' => 'Global Pest',
                'scientific_name' => 'Test',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }
}
