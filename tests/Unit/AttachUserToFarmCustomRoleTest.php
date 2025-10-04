<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\Farm\FarmController;
use App\Http\Requests\AttachUserToFarmRequest;
use App\Models\Farm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Mockery;
use Tests\TestCase;

class AttachUserToFarmCustomRoleTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $farm;
    protected $targetUser;
    protected $customRole;
    protected $permissions;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->user = User::factory()->create();
        $this->targetUser = User::factory()->create();
        $this->farm = Farm::factory()->create();

        // Create roles
        $this->customRole = Role::create(['name' => 'custom-role']);
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

        // Mock authorization - use partial mock to allow other methods
        Gate::shouldReceive('authorize')->with('attach', Mockery::type(User::class))->andReturn(true);
    }

    public function test_attach_user_with_custom_role_and_permissions()
    {
        // Create a mock request
        $request = Mockery::mock(AttachUserToFarmRequest::class);
        $request->shouldReceive('input')->with('user_id')->andReturn($this->targetUser->id);
        $request->shouldReceive('input')->with('role')->andReturn('custom-role');
        $request->shouldReceive('getValidatedPermissions')->andReturn(['view-farms', 'edit-farm']);

        // Create controller instance
        $controller = new FarmController();

        // Execute the method
        $response = $controller->attachUserToFarm($request, $this->farm);

        // Assert response
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        // Assert user is attached to farm
        $this->assertTrue($this->farm->users->contains($this->targetUser));

        // Assert user has custom-role
        $this->assertTrue($this->targetUser->hasRole('custom-role'));

        // Assert user has specific permissions
        $this->assertTrue($this->targetUser->hasPermissionTo('view-farms'));
        $this->assertTrue($this->targetUser->hasPermissionTo('edit-farm'));
    }

    public function test_attach_user_with_custom_role_without_permissions_fails()
    {
        // Create a mock request with empty permissions
        $request = Mockery::mock(AttachUserToFarmRequest::class);
        $request->shouldReceive('input')->with('user_id')->andReturn($this->targetUser->id);
        $request->shouldReceive('input')->with('role')->andReturn('custom-role');
        $request->shouldReceive('getValidatedPermissions')->andReturn([]);

        // Create controller instance
        $controller = new FarmController();

        // Execute the method
        $response = $controller->attachUserToFarm($request, $this->farm);

        // Assert response
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        // Assert user is attached to farm
        $this->assertTrue($this->farm->users->contains($this->targetUser));

        // Assert user has custom-role
        $this->assertTrue($this->targetUser->hasRole('custom-role'));

        // Assert user has no specific permissions (only role-based permissions)
        $this->assertFalse($this->targetUser->hasPermissionTo('view-farms'));
        $this->assertFalse($this->targetUser->hasPermissionTo('edit-farm'));
    }

    public function test_attach_user_with_non_custom_role_ignores_permissions()
    {
        // Create a mock request with non-custom role
        $request = Mockery::mock(AttachUserToFarmRequest::class);
        $request->shouldReceive('input')->with('user_id')->andReturn($this->targetUser->id);
        $request->shouldReceive('input')->with('role')->andReturn('operator');
        $request->shouldReceive('getValidatedPermissions')->andReturn(['view-farms', 'edit-farm']);

        // Create controller instance
        $controller = new FarmController();

        // Execute the method
        $response = $controller->attachUserToFarm($request, $this->farm);

        // Assert response
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        // Assert user is attached to farm
        $this->assertTrue($this->farm->users->contains($this->targetUser));

        // Assert user has operator role
        $this->assertTrue($this->targetUser->hasRole('operator'));

        // Assert user does not have the specific permissions (they weren't assigned)
        $this->assertFalse($this->targetUser->hasPermissionTo('view-farms'));
        $this->assertFalse($this->targetUser->hasPermissionTo('edit-farm'));
    }

    public function test_form_request_validation_rules()
    {
        $request = new AttachUserToFarmRequest();

        $rules = $request->rules();

        // Assert validation rules exist
        $this->assertArrayHasKey('user_id', $rules);
        $this->assertArrayHasKey('role', $rules);
        $this->assertArrayHasKey('permissions', $rules);
        $this->assertArrayHasKey('permissions.*', $rules);

        // Test that required rules exist (handle both string and array formats)
        $userIdRules = is_array($rules['user_id']) ? $rules['user_id'] : explode('|', $rules['user_id']);
        $this->assertContains('required', $userIdRules);
        $this->assertContains('exists:users,id', $userIdRules);

        $roleRules = is_array($rules['role']) ? $rules['role'] : explode('|', $rules['role']);
        $this->assertContains('required', $roleRules);

        $permissionsRules = is_array($rules['permissions']) ? $rules['permissions'] : explode('|', $rules['permissions']);
        $this->assertContains('nullable', $permissionsRules);
        $this->assertContains('array', $permissionsRules);

        $permissionsStarRules = is_array($rules['permissions.*']) ? $rules['permissions.*'] : explode('|', $rules['permissions.*']);
        $this->assertContains('exists:permissions,name', $permissionsStarRules);
    }

    public function test_get_validated_permissions_method()
    {
        // Create a real request instance and set the data
        $request = new AttachUserToFarmRequest();
        $request->merge([
            'role' => 'custom-role',
            'permissions' => ['view-farms', 'edit-farm']
        ]);

        $permissions = $request->getValidatedPermissions();

        $this->assertEquals(['view-farms', 'edit-farm'], $permissions);
    }

    public function test_get_validated_permissions_returns_empty_for_non_custom_role()
    {
        // Create a real request instance and set the data
        $request = new AttachUserToFarmRequest();
        $request->merge([
            'role' => 'operator',
            'permissions' => ['view-farms', 'edit-farm']
        ]);

        $permissions = $request->getValidatedPermissions();

        $this->assertEquals([], $permissions);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
