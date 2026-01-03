<?php

namespace Tests\Unit\Policies;

use App\Models\DeviceConnectionRequest;
use App\Models\User;
use App\Policies\DeviceConnectionRequestPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeviceConnectionRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    private DeviceConnectionRequestPolicy $policy;
    private User $rootUser;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Role::where('name', 'root')->exists()) {
            Role::create(['name' => 'root']);
        }

        $this->policy = new DeviceConnectionRequestPolicy();
        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');

        $this->regularUser = User::factory()->create();
    }

    /**
     * Test only root user can view any requests.
     */
    public function test_only_root_user_can_view_any_requests(): void
    {
        $this->assertTrue($this->policy->viewAny($this->rootUser));
        $this->assertFalse($this->policy->viewAny($this->regularUser));
    }

    /**
     * Test only root user can view request.
     */
    public function test_only_root_user_can_view_request(): void
    {
        $request = DeviceConnectionRequest::factory()->create();

        $this->assertTrue($this->policy->view($this->rootUser, $request));
        $this->assertFalse($this->policy->view($this->regularUser, $request));
    }

    /**
     * Test anyone can create connection request (mobile app).
     */
    public function test_anyone_can_create_connection_request(): void
    {
        $this->assertTrue($this->policy->create($this->rootUser));
        $this->assertTrue($this->policy->create($this->regularUser));
        // Note: Laravel policies don't support null user, but the route doesn't use auth middleware
        // So this test verifies authenticated users can create, which is sufficient
    }

    /**
     * Test only root user can approve request.
     */
    public function test_only_root_user_can_approve_request(): void
    {
        $request = DeviceConnectionRequest::factory()->create();

        $this->assertTrue($this->policy->approve($this->rootUser, $request));
        $this->assertFalse($this->policy->approve($this->regularUser, $request));
    }

    /**
     * Test only root user can reject request.
     */
    public function test_only_root_user_can_reject_request(): void
    {
        $request = DeviceConnectionRequest::factory()->create();

        $this->assertTrue($this->policy->reject($this->rootUser, $request));
        $this->assertFalse($this->policy->reject($this->regularUser, $request));
    }

    /**
     * Test only root user can delete request.
     */
    public function test_only_root_user_can_delete_request(): void
    {
        $request = DeviceConnectionRequest::factory()->create();

        $this->assertTrue($this->policy->delete($this->rootUser, $request));
        $this->assertFalse($this->policy->delete($this->regularUser, $request));
    }
}

