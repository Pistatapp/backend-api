<?php

namespace Tests\Unit\Policies;

use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\User;
use App\Policies\GpsDevicePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GpsDevicePolicyTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevicePolicy $policy;
    private User $rootUser;
    private User $orchardAdmin;
    private User $regularUser;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Role::where('name', 'root')->exists()) {
            Role::create(['name' => 'root']);
        }
        if (!Role::where('name', 'admin')->exists()) {
            Role::create(['name' => 'admin']);
        }

        $this->policy = new GpsDevicePolicy();
        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');

        $this->orchardAdmin = User::factory()->create();
        $this->orchardAdmin->assignRole('admin');

        $this->regularUser = User::factory()->create();

        $this->farm = Farm::factory()->create();
        $this->orchardAdmin->farms()->attach($this->farm->id, ['role' => 'admin']);
    }

    /**
     * Test root user can view any devices.
     */
    public function test_root_user_can_view_any_devices(): void
    {
        $this->assertTrue($this->policy->viewAny($this->rootUser));
    }

    /**
     * Test orchard admin can view any devices.
     */
    public function test_orchard_admin_can_view_any_devices(): void
    {
        $this->assertTrue($this->policy->viewAny($this->orchardAdmin));
    }

    /**
     * Test root user can view any device.
     */
    public function test_root_user_can_view_any_device(): void
    {
        $device = GpsDevice::factory()->create();

        $this->assertTrue($this->policy->view($this->rootUser, $device));
    }

    /**
     * Test orchard admin can view device from their farm.
     */
    public function test_orchard_admin_can_view_device_from_their_farm(): void
    {
        $device = GpsDevice::factory()->create(['farm_id' => $this->farm->id]);

        $this->assertTrue($this->policy->view($this->orchardAdmin, $device));
    }

    /**
     * Test orchard admin cannot view device from other farm.
     */
    public function test_orchard_admin_cannot_view_device_from_other_farm(): void
    {
        $otherFarm = Farm::factory()->create();
        $device = GpsDevice::factory()->create(['farm_id' => $otherFarm->id]);

        $this->assertFalse($this->policy->view($this->orchardAdmin, $device));
    }

    /**
     * Test only root user can create devices.
     */
    public function test_only_root_user_can_create_devices(): void
    {
        $this->assertTrue($this->policy->create($this->rootUser));
        $this->assertFalse($this->policy->create($this->orchardAdmin));
        $this->assertFalse($this->policy->create($this->regularUser));
    }

    /**
     * Test only root user can update devices.
     */
    public function test_only_root_user_can_update_devices(): void
    {
        $device = GpsDevice::factory()->create();

        $this->assertTrue($this->policy->update($this->rootUser, $device));
        $this->assertFalse($this->policy->update($this->orchardAdmin, $device));
        $this->assertFalse($this->policy->update($this->regularUser, $device));
    }

    /**
     * Test only root user can delete devices.
     */
    public function test_only_root_user_can_delete_devices(): void
    {
        $device = GpsDevice::factory()->create();

        $this->assertTrue($this->policy->delete($this->rootUser, $device));
        $this->assertFalse($this->policy->delete($this->orchardAdmin, $device));
        $this->assertFalse($this->policy->delete($this->regularUser, $device));
    }
}

