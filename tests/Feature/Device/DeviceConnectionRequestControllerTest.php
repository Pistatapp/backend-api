<?php

namespace Tests\Feature\Device;

use App\Models\DeviceConnectionRequest;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeviceConnectionRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $rootUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Role::where('name', 'root')->exists()) {
            Role::create(['name' => 'root']);
        }

        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');
    }

    /**
     * Test root user can list pending connection requests.
     */
    public function test_root_user_can_list_pending_connection_requests(): void
    {
        DeviceConnectionRequest::factory()->count(3)->create(['status' => 'pending']);
        DeviceConnectionRequest::factory()->create(['status' => 'approved']);

        $response = $this->actingAs($this->rootUser)->getJson('/api/device-connection-requests');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(3, $data);
        foreach ($data as $request) {
            $this->assertEquals('pending', $request['status']);
        }
    }

    /**
     * Test root user can filter requests by status.
     */
    public function test_root_user_can_filter_requests_by_status(): void
    {
        DeviceConnectionRequest::factory()->create(['status' => 'pending']);
        DeviceConnectionRequest::factory()->create(['status' => 'approved']);

        $response = $this->actingAs($this->rootUser)->getJson('/api/device-connection-requests?status=approved');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('approved', $data[0]['status']);
    }

    /**
     * Test root user can approve connection request.
     */
    public function test_root_user_can_approve_connection_request(): void
    {
        $farm = Farm::factory()->create();
        $request = DeviceConnectionRequest::factory()->create([
            'status' => 'pending',
            'mobile_number' => '09123456789',
            'device_fingerprint' => 'test-fingerprint-123',
        ]);

        $response = $this->actingAs($this->rootUser)->postJson(
            "/api/device-connection-requests/{$request->id}/approve",
            ['farm_id' => $farm->id]
        );

        $response->assertStatus(200);

        $request->refresh();
        $this->assertEquals('approved', $request->status);
        $this->assertEquals($farm->id, $request->farm_id);
        $this->assertNotNull($request->approved_at);

        // Check that device was created
        $this->assertDatabaseHas('gps_devices', [
            'device_fingerprint' => 'test-fingerprint-123',
            'mobile_number' => '09123456789',
            'farm_id' => $farm->id,
        ]);
    }

    /**
     * Test root user can reject connection request.
     */
    public function test_root_user_can_reject_connection_request(): void
    {
        $request = DeviceConnectionRequest::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($this->rootUser)->postJson(
            "/api/device-connection-requests/{$request->id}/reject",
            ['rejected_reason' => 'Invalid device information']
        );

        $response->assertStatus(200);

        $request->refresh();
        $this->assertEquals('rejected', $request->status);
        $this->assertEquals('Invalid device information', $request->rejected_reason);
        $this->assertNotNull($request->approved_at);
    }

    /**
     * Test non-root user cannot access connection requests.
     */
    public function test_non_root_user_cannot_access_connection_requests(): void
    {
        $regularUser = User::factory()->create();

        $response = $this->actingAs($regularUser)->getJson('/api/device-connection-requests');

        $response->assertStatus(403);
    }
}

