<?php

namespace Tests\Unit\Models;

use App\Models\GpsDevice;
use App\Models\Labour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabourGpsDeviceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that labour has one GPS device.
     */
    public function test_labour_has_one_gps_device(): void
    {
        $labour = Labour::factory()->create();
        $device = GpsDevice::factory()->mobilePhone()->create([
            'labour_id' => $labour->id,
        ]);

        $this->assertInstanceOf(GpsDevice::class, $labour->gpsDevice);
        $this->assertEquals($device->id, $labour->gpsDevice->id);
    }

    /**
     * Test withActiveDevice scope filters correctly.
     */
    public function test_with_active_device_scope(): void
    {
        $labour1 = Labour::factory()->create();
        $labour2 = Labour::factory()->create();
        $labour3 = Labour::factory()->create();

        GpsDevice::factory()->mobilePhone()->create([
            'labour_id' => $labour1->id,
            'is_active' => true,
        ]);
        GpsDevice::factory()->mobilePhone()->create([
            'labour_id' => $labour2->id,
            'is_active' => false,
        ]);
        // No device for labour3

        $laboursWithActiveDevices = Labour::withActiveDevice()->get();

        $this->assertCount(1, $laboursWithActiveDevices);
        $this->assertEquals($labour1->id, $laboursWithActiveDevices->first()->id);
    }
}

