<?php

namespace Tests\Unit\Services;

use App\Models\GpsDevice;
use App\Services\DeviceFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceFingerprintServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeviceFingerprintService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceFingerprintService();
    }

    /**
     * Test validateFingerprint returns true for approved and active device.
     */
    public function test_validate_fingerprint_returns_true_for_approved_active_device(): void
    {
        $fingerprint = 'test-fingerprint-123';
        GpsDevice::factory()->create([
            'device_fingerprint' => $fingerprint,
            'is_active' => true,
            'approved_at' => now(),
        ]);

        $result = $this->service->validateFingerprint($fingerprint);

        $this->assertTrue($result);
    }

    /**
     * Test validateFingerprint returns false for inactive device.
     */
    public function test_validate_fingerprint_returns_false_for_inactive_device(): void
    {
        $fingerprint = 'test-fingerprint-123';
        GpsDevice::factory()->create([
            'device_fingerprint' => $fingerprint,
            'is_active' => false,
            'approved_at' => now(),
        ]);

        $result = $this->service->validateFingerprint($fingerprint);

        $this->assertFalse($result);
    }

    /**
     * Test validateFingerprint returns false for unapproved device.
     */
    public function test_validate_fingerprint_returns_false_for_unapproved_device(): void
    {
        $fingerprint = 'test-fingerprint-123';
        GpsDevice::factory()->create([
            'device_fingerprint' => $fingerprint,
            'is_active' => true,
            'approved_at' => null,
        ]);

        $result = $this->service->validateFingerprint($fingerprint);

        $this->assertFalse($result);
    }

    /**
     * Test validateFingerprint returns false for non-existent device.
     */
    public function test_validate_fingerprint_returns_false_for_non_existent_device(): void
    {
        $result = $this->service->validateFingerprint('non-existent-fingerprint');

        $this->assertFalse($result);
    }

    /**
     * Test getDeviceByFingerprint returns device.
     */
    public function test_get_device_by_fingerprint_returns_device(): void
    {
        $fingerprint = 'test-fingerprint-123';
        $device = GpsDevice::factory()->create(['device_fingerprint' => $fingerprint]);

        $result = $this->service->getDeviceByFingerprint($fingerprint);

        $this->assertInstanceOf(GpsDevice::class, $result);
        $this->assertEquals($device->id, $result->id);
    }

    /**
     * Test getDeviceByFingerprint returns null for non-existent device.
     */
    public function test_get_device_by_fingerprint_returns_null_for_non_existent(): void
    {
        $result = $this->service->getDeviceByFingerprint('non-existent-fingerprint');

        $this->assertNull($result);
    }

    /**
     * Test isDeviceApproved returns true for approved device.
     */
    public function test_is_device_approved_returns_true_for_approved_device(): void
    {
        $fingerprint = 'test-fingerprint-123';
        GpsDevice::factory()->create([
            'device_fingerprint' => $fingerprint,
            'approved_at' => now(),
        ]);

        $result = $this->service->isDeviceApproved($fingerprint);

        $this->assertTrue($result);
    }

    /**
     * Test isDeviceApproved returns false for unapproved device.
     */
    public function test_is_device_approved_returns_false_for_unapproved_device(): void
    {
        $fingerprint = 'test-fingerprint-123';
        GpsDevice::factory()->create([
            'device_fingerprint' => $fingerprint,
            'approved_at' => null,
        ]);

        $result = $this->service->isDeviceApproved($fingerprint);

        $this->assertFalse($result);
    }
}

