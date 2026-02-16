<?php

namespace Tests\Unit\Services;

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

        $data = [
            'device_type' => 'personal_gps',
            'name' => 'Test GPS Device',
            'imei' => '123456789012345',
            'sim_number' => '09123456789',
        ];

        $device = $this->service->createPersonalGpsDevice($data, $user->id);

        $this->assertInstanceOf(GpsDevice::class, $device);
        $this->assertEquals('personal_gps', $device->device_type);
        $this->assertEquals($user->id, $device->user_id);
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
     * Test assignDeviceToWorker returns device (no-op: labour_id removed from gps_devices).
     */
    public function test_assign_device_to_worker_returns_device(): void
    {
        $labour = Labour::factory()->create();
        $device = GpsDevice::factory()->create([
            'device_type' => 'mobile_phone',
            'device_fingerprint' => 'test-fingerprint-123',
        ]);

        $result = $this->service->assignDeviceToWorker($device->id, $labour->id);

        $this->assertInstanceOf(GpsDevice::class, $result);
        $this->assertEquals($device->id, $result->id);
    }

    /**
     * Test replaceWorkerDevice returns new device (no-op: labour/is_active removed from gps_devices).
     */
    public function test_replace_worker_device_returns_new_device(): void
    {
        $labour = Labour::factory()->create();
        $oldDevice = GpsDevice::factory()->create();
        $newDevice = GpsDevice::factory()->create();

        $result = $this->service->replaceWorkerDevice($oldDevice->id, $newDevice->id, $labour->id);

        $this->assertEquals($newDevice->id, $result->id);
    }

    /**
     * Test deactivateDevice returns device (no-op: is_active removed from gps_devices).
     */
    public function test_deactivate_device_returns_device(): void
    {
        $device = GpsDevice::factory()->create();

        $result = $this->service->deactivateDevice($device->id);

        $this->assertInstanceOf(GpsDevice::class, $result);
        $this->assertEquals($device->id, $result->id);
    }
}

