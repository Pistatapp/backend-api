<?php

namespace Tests\Feature\Device;

use App\Models\DeviceConnectionRequest;
use App\Models\GpsDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test mobile app can create connection request.
     */
    public function test_mobile_app_can_create_connection_request(): void
    {
        $response = $this->postJson('/api/mobile/connect', [
            'mobile_number' => '09123456789',
            'device_fingerprint' => 'test-fingerprint-123',
            'device_info' => [
                'model' => 'iPhone 13',
                'os_version' => '15.0',
                'app_version' => '1.0.0',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['status', 'message', 'request_id']
        ]);

        $this->assertDatabaseHas('device_connection_requests', [
            'mobile_number' => '09123456789',
            'device_fingerprint' => 'test-fingerprint-123',
            'status' => 'pending',
        ]);
    }

    /**
     * Test mobile app receives already connected status for approved device.
     */
    public function test_mobile_app_receives_already_connected_status_for_approved_device(): void
    {
        $fingerprint = 'test-fingerprint-123';
        GpsDevice::factory()->create([
            'device_fingerprint' => $fingerprint,
            'approved_at' => now(),
        ]);

        $response = $this->postJson('/api/mobile/connect', [
            'mobile_number' => '09123456789',
            'device_fingerprint' => $fingerprint,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'status' => 'connected',
            ]
        ]);
    }

    /**
     * Test mobile app receives pending status for existing pending request.
     */
    public function test_mobile_app_receives_pending_status_for_existing_pending_request(): void
    {
        $fingerprint = 'test-fingerprint-123';
        DeviceConnectionRequest::factory()->create([
            'device_fingerprint' => $fingerprint,
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/mobile/connect', [
            'mobile_number' => '09123456789',
            'device_fingerprint' => $fingerprint,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'status' => 'pending',
            ]
        ]);
    }

    /**
     * Test connection request requires valid data.
     */
    public function test_connection_request_requires_valid_data(): void
    {
        $response = $this->postJson('/api/mobile/connect', [
            'mobile_number' => '',
            'device_fingerprint' => '',
        ]);

        $response->assertStatus(422);
    }
}

