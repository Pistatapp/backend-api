<?php

namespace Tests\Unit\Services;

use App\Models\DeviceConnectionRequest;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Labour;
use App\Models\Tractor;
use App\Models\User;
use App\Services\DeviceManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeviceManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceManagementService();
    }

    /**
     * Test createPersonalGpsDevice creates device correctly.
     */
    public function test_create_personal_gps_device_creates_device_correctly(): void
    {
        $user = User::factory()->create();
        $farm = Farm::factory()->create();

        $data = [
            'device_type' => 'personal_gps',
            'name' => 'Test GPS Device',
            'imei' => '123456789012345',
            'sim_number' => '09123456789',
            'farm_id' => $farm->id,
        ];

        $device = $this->service->createPersonalGpsDevice($data, $user->id);

        $this->assertInstanceOf(GpsDevice::class, $device);
        $this->assertEquals('personal_gps', $device->device_type);
        $this->assertEquals($user->id, $device->user_id);
        $this->assertEquals($farm->id, $device->farm_id);
        $this->assertTrue($device->is_active);
        $this->assertNotNull($device->approved_at);
        $this->assertEquals($user->id, $device->approved_by);
    }

    /**
     * Test createPersonalGpsDevice creates tractor GPS device correctly.
     */
    public function test_create_personal_gps_device_creates_tractor_gps_device(): void
    {
        $user = User::factory()->create();
        $tractor = Tractor::factory()->create();

        $data = [
            'device_type' => 'tractor_gps',
            'name' => 'Tractor GPS',
            'imei' => '123456789012345',
            'tractor_id' => $tractor->id,
        ];

        $device = $this->service->createPersonalGpsDevice($data, $user->id);

        $this->assertEquals('tractor_gps', $device->device_type);
        $this->assertEquals($tractor->id, $device->tractor_id);
    }

    /**
     * Test approveConnectionRequest creates device and updates request.
     */
    public function test_approve_connection_request_creates_device_and_updates_request(): void
    {
        $user = User::factory()->create();
        $farm = Farm::factory()->create();
        $request = DeviceConnectionRequest::factory()->create([
            'mobile_number' => '09123456789',
            'device_fingerprint' => 'test-fingerprint-123',
            'status' => 'pending',
        ]);

        $device = $this->service->approveConnectionRequest($request->id, $farm->id, $user->id);

        $this->assertInstanceOf(GpsDevice::class, $device);
        $this->assertEquals('mobile_phone', $device->device_type);
        $this->assertEquals('test-fingerprint-123', $device->device_fingerprint);
        $this->assertEquals($farm->id, $device->farm_id);
        $this->assertTrue($device->is_active);

        $request->refresh();
        $this->assertEquals('approved', $request->status);
        $this->assertEquals($farm->id, $request->farm_id);
        $this->assertEquals($user->id, $request->approved_by);
    }

    /**
     * Test assignDeviceToWorker assigns device correctly.
     */
    public function test_assign_device_to_worker_assigns_device_correctly(): void
    {
        $labour = Labour::factory()->create();
        $device = GpsDevice::factory()->create([
            'device_type' => 'mobile_phone',
            'device_fingerprint' => 'test-fingerprint-123',
            'mobile_number' => '09123456789',
            'labour_id' => null,
        ]);

        $result = $this->service->assignDeviceToWorker($device->id, $labour->id);

        $this->assertEquals($labour->id, $result->labour_id);
    }

    /**
     * Test replaceWorkerDevice deactivates old and assigns new.
     */
    public function test_replace_worker_device_deactivates_old_and_assigns_new(): void
    {
        $labour = Labour::factory()->create();
        $oldDevice = GpsDevice::factory()->create([
            'labour_id' => $labour->id,
            'is_active' => true,
        ]);
        $newDevice = GpsDevice::factory()->create([
            'labour_id' => null,
            'is_active' => true,
        ]);

        $result = $this->service->replaceWorkerDevice($oldDevice->id, $newDevice->id, $labour->id);

        $this->assertEquals($labour->id, $result->labour_id);
        $this->assertEquals($newDevice->id, $result->id);

        $oldDevice->refresh();
        $this->assertFalse($oldDevice->is_active);
        $this->assertNull($oldDevice->labour_id);
    }

    /**
     * Test deactivateDevice deactivates device.
     */
    public function test_deactivate_device_deactivates_device(): void
    {
        $device = GpsDevice::factory()->create(['is_active' => true]);

        $result = $this->service->deactivateDevice($device->id);

        $this->assertFalse($result->is_active);
    }
}

