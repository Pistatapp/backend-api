<?php

namespace Tests\Feature\Device;

use App\Models\GpsDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test connect returns 404 when no user for mobile number.
     */
    public function test_connect_returns_not_found_when_user_missing(): void
    {
        $response = $this->postJson('/api/mobile/connect', [
            'mobile_number' => '09123456789',
            'device_fingerprint' => 'test-fingerprint-123',
            'imei' => '1234567890123456',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['status' => 'not_found', 'message' => 'No user found for this mobile number']);
    }

    /**
     * Test connect returns 404 when user exists but has no GPS device.
     */
    public function test_connect_returns_not_found_when_no_device_for_user(): void
    {
        User::factory()->create(['mobile' => '09123456789']);

        $response = $this->postJson('/api/mobile/connect', [
            'mobile_number' => '09123456789',
            'device_fingerprint' => 'test-fingerprint-123',
            'imei' => '1234567890123456',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['status' => 'not_found', 'message' => 'No device record found for this user']);
    }

    /**
     * Test connect returns already connected when device has IMEI set.
     */
    public function test_connect_returns_already_connected_when_imei_filled(): void
    {
        $user = User::factory()->create(['mobile' => '09123456789']);
        $device = GpsDevice::factory()->create([
            'user_id' => $user->id,
            'device_fingerprint' => 'test-fingerprint-123',
            'device_type' => 'mobile_phone',
            'imei' => '1234567890123456',
        ]);

        $response = $this->postJson('/api/mobile/connect', [
            'mobile_number' => '09123456789',
            'device_fingerprint' => 'test-fingerprint-123',
            'imei' => '9999999999999999',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'connected',
            'message' => 'Device is already connected and approved',
            'device_id' => $device->id,
        ]);
    }

    /**
     * Test connect updates IMEI and returns success when device exists without IMEI.
     */
    public function test_connect_updates_imei_and_returns_success(): void
    {
        $user = User::factory()->create(['mobile' => '09123456789']);
        $device = GpsDevice::factory()->create([
            'user_id' => $user->id,
            'device_fingerprint' => 'test-fingerprint-123',
            'device_type' => 'mobile_phone',
            'imei' => null,
        ]);

        $response = $this->postJson('/api/mobile/connect', [
            'mobile_number' => '09123456789',
            'device_fingerprint' => 'test-fingerprint-123',
            'imei' => '1234567890123456',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'connected',
            'message' => 'Device connected successfully',
            'device_id' => $device->id,
        ]);

        $device->refresh();
        $this->assertEquals('1234567890123456', $device->imei);
    }

    /**
     * Test connect requires valid data (mobile_number, device_fingerprint, imei).
     */
    public function test_connect_requires_valid_data(): void
    {
        $response = $this->postJson('/api/mobile/connect', [
            'mobile_number' => '',
            'device_fingerprint' => '',
            'imei' => 'invalid',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Test requestStatus returns approved with device_id when device has IMEI.
     */
    public function test_request_status_returns_approved_when_device_has_imei(): void
    {
        $device = GpsDevice::factory()->create([
            'device_fingerprint' => 'test-fingerprint-123',
            'imei' => '1234567890123456',
        ]);

        $response = $this->postJson('/api/mobile/request-status', [
            'device_fingerprint' => 'test-fingerprint-123',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'approved',
            'message' => 'Device is connected and approved',
            'device_id' => $device->id,
        ]);
    }

    /**
     * Test requestStatus returns 404 when no approved device for fingerprint.
     */
    public function test_request_status_returns_not_found_when_no_device(): void
    {
        $response = $this->postJson('/api/mobile/request-status', [
            'device_fingerprint' => 'unknown-fingerprint',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['status' => 'not_found']);
    }

    /**
     * Test requestStatus returns 404 when device exists but IMEI not set.
     */
    public function test_request_status_returns_not_found_when_imei_empty(): void
    {
        GpsDevice::factory()->create([
            'device_fingerprint' => 'test-fingerprint-123',
            'imei' => null,
        ]);

        $response = $this->postJson('/api/mobile/request-status', [
            'device_fingerprint' => 'test-fingerprint-123',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['status' => 'not_found']);
    }
}
