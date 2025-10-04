<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachUserToFarmCustomRoleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $targetUser;
    protected $farm;
    protected $permissions;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->user = User::factory()->create();
        $this->targetUser = User::factory()->create();
        $this->farm = Farm::factory()->create();

        // Create roles
        Role::create(['name' => 'custom-role']);
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'operator']);

        // Create test permissions
        $this->permissions = [
            Permission::create(['name' => 'view-farms']),
            Permission::create(['name' => 'edit-farm']),
            Permission::create(['name' => 'delete-farm']),
        ];

        // Attach user to farm as owner
        $this->farm->users()->attach($this->user->id, [
            'role' => 'admin',
            'is_owner' => true,
        ]);

        // Give user admin role for authorization
        $this->user->assignRole('admin');
    }

    public function test_attach_user_with_custom_role_and_permissions_via_api()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/attach_user", [
                'user_id' => $this->targetUser->id,
                'role' => 'custom-role',
                'permissions' => ['view-farms', 'edit-farm']
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'User attached to farm successfully.']);

        // Assert user is attached to farm
        $this->assertTrue($this->farm->users->contains($this->targetUser));

        // Assert user has custom-role
        $this->assertTrue($this->targetUser->hasRole('custom-role'));

        // Assert user has specific permissions
        $this->assertTrue($this->targetUser->hasPermissionTo('view-farms'));
        $this->assertTrue($this->targetUser->hasPermissionTo('edit-farm'));
        $this->assertFalse($this->targetUser->hasPermissionTo('delete-farm'));
    }

    public function test_attach_user_with_custom_role_without_permissions_fails_validation()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/attach_user", [
                'user_id' => $this->targetUser->id,
                'role' => 'custom-role',
                // No permissions provided
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions']);
    }

    public function test_attach_user_with_invalid_permissions_fails_validation()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/attach_user", [
                'user_id' => $this->targetUser->id,
                'role' => 'custom-role',
                'permissions' => ['invalid-permission', 'another-invalid-permission']
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0', 'permissions.1']);
    }

    public function test_attach_user_with_non_custom_role_ignores_permissions()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/attach_user", [
                'user_id' => $this->targetUser->id,
                'role' => 'operator',
                'permissions' => ['view-farms', 'edit-farm'] // These should be ignored
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'User attached to farm successfully.']);

        // Assert user is attached to farm
        $this->assertTrue($this->farm->users->contains($this->targetUser));

        // Assert user has operator role
        $this->assertTrue($this->targetUser->hasRole('operator'));

        // Assert user does not have the specific permissions (they weren't assigned)
        $this->assertFalse($this->targetUser->hasPermissionTo('view-farms'));
        $this->assertFalse($this->targetUser->hasPermissionTo('edit-farm'));
    }
}
