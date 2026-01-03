<?php

namespace Tests\Feature\Device;

use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Labour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkerDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $orchardAdmin;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Role::where('name', 'orchard_admin')->exists()) {
            Role::create(['name' => 'orchard_admin']);
        }

        $this->farm = Farm::factory()->create();
        $this->orchardAdmin = User::factory()->create();
        $this->orchardAdmin->assignRole('orchard_admin');
        $this->orchardAdmin->farms()->attach($this->farm->id, ['role' => 'admin']);
    }

    /**
     * Test orchard admin can list worker devices for their farm.
     */
    public function test_orchard_admin_can_list_worker_devices_for_their_farm(): void
    {
        GpsDevice::factory()->create([
            'farm_id' => $this->farm->id,
            'device_type' => 'mobile_phone',
        ]);
        GpsDevice::factory()->create([
            'farm_id' => $this->farm->id,
            'device_type' => 'personal_gps',
        ]);
        // Device from different farm
        $otherFarm = Farm::factory()->create();
        GpsDevice::factory()->create([
            'farm_id' => $otherFarm->id,
            'device_type' => 'mobile_phone',
        ]);

        $response = $this->actingAs($this->orchardAdmin)->getJson("/api/farms/{$this->farm->id}/worker-devices");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    /**
     * Test orchard admin can assign device to worker.
     */
    public function test_orchard_admin_can_assign_device_to_worker(): void
    {
        $labour = Labour::factory()->create(['farm_id' => $this->farm->id]);
        $device = GpsDevice::factory()->create([
            'farm_id' => $this->farm->id,
            'device_type' => 'mobile_phone',
            'labour_id' => null,
        ]);

        $response = $this->actingAs($this->orchardAdmin)->putJson(
            "/api/worker-devices/{$device->id}/assign",
            ['labour_id' => $labour->id]
        );

        $response->assertStatus(200);
        $device->refresh();
        $this->assertEquals($labour->id, $device->labour_id);
    }

    /**
     * Test orchard admin cannot assign device to worker from different farm.
     */
    public function test_orchard_admin_cannot_assign_device_to_worker_from_different_farm(): void
    {
        $otherFarm = Farm::factory()->create();
        $labour = Labour::factory()->create(['farm_id' => $otherFarm->id]);
        $device = GpsDevice::factory()->create([
            'farm_id' => $this->farm->id,
            'device_type' => 'mobile_phone',
        ]);

        $response = $this->actingAs($this->orchardAdmin)->putJson(
            "/api/worker-devices/{$device->id}/assign",
            ['labour_id' => $labour->id]
        );

        $response->assertStatus(403);
    }

    /**
     * Test orchard admin can unassign device from worker.
     */
    public function test_orchard_admin_can_unassign_device_from_worker(): void
    {
        $labour = Labour::factory()->create(['farm_id' => $this->farm->id]);
        $device = GpsDevice::factory()->create([
            'farm_id' => $this->farm->id,
            'labour_id' => $labour->id,
        ]);

        $response = $this->actingAs($this->orchardAdmin)->putJson("/api/worker-devices/{$device->id}/unassign");

        $response->assertStatus(200);
        $device->refresh();
        $this->assertNull($device->labour_id);
    }

    /**
     * Test device replacement deactivates old device.
     */
    public function test_device_replacement_deactivates_old_device(): void
    {
        $labour = Labour::factory()->create(['farm_id' => $this->farm->id]);
        $oldDevice = GpsDevice::factory()->create([
            'farm_id' => $this->farm->id,
            'labour_id' => $labour->id,
            'is_active' => true,
        ]);
        $newDevice = GpsDevice::factory()->create([
            'farm_id' => $this->farm->id,
            'labour_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($this->orchardAdmin)->putJson(
            "/api/worker-devices/{$newDevice->id}/assign",
            ['labour_id' => $labour->id]
        );

        $oldDevice->refresh();
        $this->assertFalse($oldDevice->is_active);
    }

    /**
     * Test orchard admin cannot access devices from other farms.
     */
    public function test_orchard_admin_cannot_access_devices_from_other_farms(): void
    {
        $otherFarm = Farm::factory()->create();
        $device = GpsDevice::factory()->create(['farm_id' => $otherFarm->id]);

        $response = $this->actingAs($this->orchardAdmin)->putJson(
            "/api/worker-devices/{$device->id}/assign",
            ['labour_id' => Labour::factory()->create(['farm_id' => $otherFarm->id])->id]
        );

        $response->assertStatus(403);
    }
}

