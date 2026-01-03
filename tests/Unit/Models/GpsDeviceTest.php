<?php

namespace Tests\Unit\Models;

use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Labour;
use App\Models\Tractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GpsDeviceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that GpsDevice belongs to user.
     */
    public function test_gps_device_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $device = GpsDevice::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $device->user);
        $this->assertEquals($user->id, $device->user->id);
    }

    /**
     * Test that GpsDevice belongs to tractor.
     */
    public function test_gps_device_belongs_to_tractor(): void
    {
        $tractor = Tractor::factory()->create();
        $device = GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'device_type' => 'tractor_gps',
        ]);

        $this->assertInstanceOf(Tractor::class, $device->tractor);
        $this->assertEquals($tractor->id, $device->tractor->id);
    }

    /**
     * Test that GpsDevice belongs to labour.
     */
    public function test_gps_device_belongs_to_labour(): void
    {
        $labour = Labour::factory()->create();
        $device = GpsDevice::factory()->create([
            'labour_id' => $labour->id,
            'device_type' => 'mobile_phone',
            'device_fingerprint' => 'test-fingerprint-123',
            'mobile_number' => '09123456789',
        ]);

        $this->assertInstanceOf(Labour::class, $device->labour);
        $this->assertEquals($labour->id, $device->labour->id);
    }

    /**
     * Test that GpsDevice belongs to farm.
     */
    public function test_gps_device_belongs_to_farm(): void
    {
        $farm = Farm::factory()->create();
        $device = GpsDevice::factory()->create([
            'farm_id' => $farm->id,
            'device_type' => 'personal_gps',
        ]);

        $this->assertInstanceOf(Farm::class, $device->farm);
        $this->assertEquals($farm->id, $device->farm->id);
    }

    /**
     * Test that GpsDevice has approver.
     */
    public function test_gps_device_has_approver(): void
    {
        $approver = User::factory()->create();
        $device = GpsDevice::factory()->create([
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        $this->assertInstanceOf(User::class, $device->approver);
        $this->assertEquals($approver->id, $device->approver->id);
    }

    /**
     * Test workerDevices scope filters correctly.
     */
    public function test_worker_devices_scope(): void
    {
        GpsDevice::factory()->create(['device_type' => 'mobile_phone']);
        GpsDevice::factory()->create(['device_type' => 'personal_gps']);
        GpsDevice::factory()->create(['device_type' => 'tractor_gps']);

        $workerDevices = GpsDevice::workerDevices()->get();

        $this->assertCount(2, $workerDevices);
        $workerDevices->each(function ($device) {
            $this->assertContains($device->device_type, ['mobile_phone', 'personal_gps']);
        });
    }

    /**
     * Test tractorDevices scope filters correctly.
     */
    public function test_tractor_devices_scope(): void
    {
        $tractor = Tractor::factory()->create();
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'device_type' => 'tractor_gps',
        ]);
        GpsDevice::factory()->create([
            'tractor_id' => null,
            'device_type' => 'mobile_phone',
        ]);

        $tractorDevices = GpsDevice::tractorDevices()->get();

        $this->assertCount(1, $tractorDevices);
        $this->assertNotNull($tractorDevices->first()->tractor_id);
    }

    /**
     * Test active scope filters correctly.
     */
    public function test_active_scope(): void
    {
        GpsDevice::factory()->create(['is_active' => true]);
        GpsDevice::factory()->create(['is_active' => false]);

        $activeDevices = GpsDevice::active()->get();

        $this->assertCount(1, $activeDevices);
        $this->assertTrue($activeDevices->first()->is_active);
    }

    /**
     * Test unassigned scope filters correctly.
     */
    public function test_unassigned_scope(): void
    {
        $tractor = Tractor::factory()->create();
        $labour = Labour::factory()->create();

        GpsDevice::factory()->create(['tractor_id' => $tractor->id, 'labour_id' => null]);
        GpsDevice::factory()->create(['tractor_id' => null, 'labour_id' => $labour->id]);
        GpsDevice::factory()->create(['tractor_id' => null, 'labour_id' => null]);

        $unassignedDevices = GpsDevice::unassigned()->get();

        $this->assertCount(1, $unassignedDevices);
        $this->assertNull($unassignedDevices->first()->tractor_id);
        $this->assertNull($unassignedDevices->first()->labour_id);
    }

    /**
     * Test forFarm scope filters correctly.
     */
    public function test_for_farm_scope(): void
    {
        $farm1 = Farm::factory()->create();
        $farm2 = Farm::factory()->create();

        GpsDevice::factory()->create(['farm_id' => $farm1->id]);
        GpsDevice::factory()->create(['farm_id' => $farm2->id]);
        GpsDevice::factory()->create(['farm_id' => null]);

        $farm1Devices = GpsDevice::forFarm($farm1->id)->get();

        $this->assertCount(1, $farm1Devices);
        $this->assertEquals($farm1->id, $farm1Devices->first()->farm_id);
    }

    /**
     * Test is_active is cast to boolean.
     */
    public function test_is_active_is_cast_to_boolean(): void
    {
        $device = GpsDevice::factory()->create(['is_active' => true]);

        $this->assertIsBool($device->is_active);
        $this->assertTrue($device->is_active);
    }

    /**
     * Test approved_at is cast to datetime.
     */
    public function test_approved_at_is_cast_to_datetime(): void
    {
        $device = GpsDevice::factory()->create(['approved_at' => now()]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $device->approved_at);
    }
}

