<?php

namespace Tests\Feature\Device;

use App\Events\LabourStatusChanged;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Labour;
use App\Models\LabourGpsData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MobileGpsControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test mobile app can submit GPS data with valid device fingerprint.
     */
    public function test_mobile_app_can_submit_gps_data_with_valid_device_fingerprint(): void
    {
        Event::fake();

        $farm = Farm::factory()->create();
        $labour = Labour::factory()->create(['farm_id' => $farm->id]);
        $device = GpsDevice::factory()->create([
            'device_fingerprint' => 'test-fingerprint-123',
            'mobile_number' => '09123456789',
            'labour_id' => $labour->id,
            'is_active' => true,
            'approved_at' => now(),
        ]);

        // Set farm coordinates for boundary detection
        $farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $farm->save();

        $response = $this->postJson('/api/mobile/gps', [
            'device_fingerprint' => 'test-fingerprint-123',
            'mobile_number' => '09123456789',
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'altitude' => 1200.5,
            'speed' => 0.0,
            'time' => now()->getTimestampMs(),
            'status' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('labour_gps_data', [
            'labour_id' => $labour->id,
            'provider' => 'mobile',
        ]);

        Event::assertDispatched(LabourStatusChanged::class);
    }

    /**
     * Test mobile app cannot submit GPS data with invalid device fingerprint.
     */
    public function test_mobile_app_cannot_submit_gps_data_with_invalid_device_fingerprint(): void
    {
        $response = $this->postJson('/api/mobile/gps', [
            'device_fingerprint' => 'invalid-fingerprint',
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Device not approved or inactive']);
    }

    /**
     * Test mobile app cannot submit GPS data with inactive device.
     */
    public function test_mobile_app_cannot_submit_gps_data_with_inactive_device(): void
    {
        $labour = Labour::factory()->create();
        GpsDevice::factory()->create([
            'device_fingerprint' => 'test-fingerprint-123',
            'labour_id' => $labour->id,
            'is_active' => false,
            'approved_at' => now(),
        ]);

        $response = $this->postJson('/api/mobile/gps', [
            'device_fingerprint' => 'test-fingerprint-123',
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test mobile app cannot submit GPS data with unapproved device.
     */
    public function test_mobile_app_cannot_submit_gps_data_with_unapproved_device(): void
    {
        $labour = Labour::factory()->create();
        GpsDevice::factory()->create([
            'device_fingerprint' => 'test-fingerprint-123',
            'labour_id' => $labour->id,
            'is_active' => true,
            'approved_at' => null,
        ]);

        $response = $this->postJson('/api/mobile/gps', [
            'device_fingerprint' => 'test-fingerprint-123',
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test mobile app cannot submit GPS data when device not assigned to worker.
     */
    public function test_mobile_app_cannot_submit_gps_data_when_device_not_assigned_to_worker(): void
    {
        GpsDevice::factory()->create([
            'device_fingerprint' => 'test-fingerprint-123',
            'labour_id' => null,
            'is_active' => true,
            'approved_at' => now(),
        ]);

        $response = $this->postJson('/api/mobile/gps', [
            'device_fingerprint' => 'test-fingerprint-123',
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Device not assigned to a worker']);
    }

    /**
     * Test GPS data submission requires valid coordinates.
     */
    public function test_gps_data_submission_requires_valid_coordinates(): void
    {
        $response = $this->postJson('/api/mobile/gps', [
            'device_fingerprint' => 'test-fingerprint-123',
            'latitude' => 999, // Invalid latitude
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(422);
    }

    /**
     * Test GPS data is saved correctly.
     */
    public function test_gps_data_is_saved_correctly(): void
    {
        $farm = Farm::factory()->create();
        $labour = Labour::factory()->create(['farm_id' => $farm->id]);
        $device = GpsDevice::factory()->create([
            'device_fingerprint' => 'test-fingerprint-123',
            'labour_id' => $labour->id,
            'is_active' => true,
            'approved_at' => now(),
        ]);

        $farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $farm->save();

        $latitude = 35.6895;
        $longitude = 51.3895;
        $altitude = 1200.5;
        $speed = 5.5;

        $this->postJson('/api/mobile/gps', [
            'device_fingerprint' => 'test-fingerprint-123',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'altitude' => $altitude,
            'speed' => $speed,
            'time' => now()->getTimestampMs(),
            'status' => 1,
        ]);

        $gpsData = LabourGpsData::where('labour_id', $labour->id)->first();
        $this->assertNotNull($gpsData);
        $this->assertEquals($latitude, $gpsData->coordinate['lat']);
        $this->assertEquals($longitude, $gpsData->coordinate['lng']);
        $this->assertEquals($altitude, $gpsData->coordinate['altitude']);
        $this->assertEquals($speed, $gpsData->speed);
        $this->assertEquals('mobile', $gpsData->provider);
    }
}

