<?php

namespace Tests\Feature\Device;

use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Labour;
use App\Models\Tractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $rootUser;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles if they don't exist
        if (!Role::where('name', 'root')->exists()) {
            Role::create(['name' => 'root']);
        }
        if (!Role::where('name', 'orchard_admin')->exists()) {
            Role::create(['name' => 'orchard_admin']);
        }

        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');

        $this->regularUser = User::factory()->create();
    }

    /**
     * Test root user can list all devices.
     */
    public function test_root_user_can_list_all_devices(): void
    {
        GpsDevice::factory()->count(5)->create();

        $response = $this->actingAs($this->rootUser)->getJson('/api/devices');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'device_type', 'name', 'imei']
            ]
        ]);
    }

    /**
     * Test root user can filter devices by type.
     */
    public function test_root_user_can_filter_devices_by_type(): void
    {
        GpsDevice::factory()->create(['device_type' => 'mobile_phone']);
        GpsDevice::factory()->create(['device_type' => 'personal_gps']);
        GpsDevice::factory()->create(['device_type' => 'tractor_gps']);

        $response = $this->actingAs($this->rootUser)->getJson('/api/devices?type=worker');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $device) {
            $this->assertContains($device['device_type'], ['mobile_phone', 'personal_gps']);
        }
    }

    /**
     * Test non-root user cannot access device management.
     */
    public function test_non_root_user_cannot_access_device_management(): void
    {
        $response = $this->actingAs($this->regularUser)->getJson('/api/devices');

        $response->assertStatus(403);
    }

    /**
     * Test root user can create personal GPS device.
     */
    public function test_root_user_can_create_personal_gps_device(): void
    {
        $farm = Farm::factory()->create();

        $response = $this->actingAs($this->rootUser)->postJson('/api/devices', [
            'device_type' => 'personal_gps',
            'name' => 'Test GPS Device',
            'imei' => '123456789012345',
            'sim_number' => '09123456789',
            'farm_id' => $farm->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'device_type', 'name', 'imei', 'is_active']
        ]);

        $this->assertDatabaseHas('gps_devices', [
            'device_type' => 'personal_gps',
            'name' => 'Test GPS Device',
            'imei' => '123456789012345',
        ]);
    }

    /**
     * Test root user can create tractor GPS device.
     */
    public function test_root_user_can_create_tractor_gps_device(): void
    {
        $tractor = Tractor::factory()->create();

        $response = $this->actingAs($this->rootUser)->postJson('/api/devices', [
            'device_type' => 'tractor_gps',
            'name' => 'Tractor GPS',
            'imei' => '123456789012345',
            'tractor_id' => $tractor->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('gps_devices', [
            'device_type' => 'tractor_gps',
            'tractor_id' => $tractor->id,
        ]);
    }

    /**
     * Test device creation requires valid data.
     */
    public function test_device_creation_requires_valid_data(): void
    {
        $response = $this->actingAs($this->rootUser)->postJson('/api/devices', [
            'device_type' => 'invalid_type',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Test root user can view device details.
     */
    public function test_root_user_can_view_device_details(): void
    {
        $device = GpsDevice::factory()->create();

        $response = $this->actingAs($this->rootUser)->getJson("/api/devices/{$device->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['id', 'device_type', 'name', 'imei', 'user', 'farm']
        ]);
    }

    /**
     * Test root user can update device.
     */
    public function test_root_user_can_update_device(): void
    {
        $device = GpsDevice::factory()->create();
        $newFarm = Farm::factory()->create();

        $response = $this->actingAs($this->rootUser)->putJson("/api/devices/{$device->id}", [
            'name' => 'Updated Device Name',
            'farm_id' => $newFarm->id,
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('gps_devices', [
            'id' => $device->id,
            'name' => 'Updated Device Name',
            'farm_id' => $newFarm->id,
            'is_active' => false,
        ]);
    }

    /**
     * Test root user can delete device.
     */
    public function test_root_user_can_delete_device(): void
    {
        $device = GpsDevice::factory()->create();

        $response = $this->actingAs($this->rootUser)->deleteJson("/api/devices/{$device->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('gps_devices', ['id' => $device->id]);
    }
}

