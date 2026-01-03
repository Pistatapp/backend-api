<?php

namespace Tests\Unit\Models;

use App\Models\DeviceConnectionRequest;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceConnectionRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that DeviceConnectionRequest belongs to farm.
     */
    public function test_device_connection_request_belongs_to_farm(): void
    {
        $farm = Farm::factory()->create();
        $request = DeviceConnectionRequest::factory()->create(['farm_id' => $farm->id]);

        $this->assertInstanceOf(Farm::class, $request->farm);
        $this->assertEquals($farm->id, $request->farm->id);
    }

    /**
     * Test that DeviceConnectionRequest belongs to approver.
     */
    public function test_device_connection_request_belongs_to_approver(): void
    {
        $approver = User::factory()->create();
        $request = DeviceConnectionRequest::factory()->create(['approved_by' => $approver->id]);

        $this->assertInstanceOf(User::class, $request->approver);
        $this->assertEquals($approver->id, $request->approver->id);
    }

    /**
     * Test pending scope filters correctly.
     */
    public function test_pending_scope(): void
    {
        DeviceConnectionRequest::factory()->create(['status' => 'pending']);
        DeviceConnectionRequest::factory()->create(['status' => 'approved']);
        DeviceConnectionRequest::factory()->create(['status' => 'rejected']);

        $pendingRequests = DeviceConnectionRequest::pending()->get();

        $this->assertCount(1, $pendingRequests);
        $this->assertEquals('pending', $pendingRequests->first()->status);
    }

    /**
     * Test approved scope filters correctly.
     */
    public function test_approved_scope(): void
    {
        DeviceConnectionRequest::factory()->create(['status' => 'pending']);
        DeviceConnectionRequest::factory()->approved()->create();

        $approvedRequests = DeviceConnectionRequest::approved()->get();

        $this->assertCount(1, $approvedRequests);
        $this->assertEquals('approved', $approvedRequests->first()->status);
    }

    /**
     * Test rejected scope filters correctly.
     */
    public function test_rejected_scope(): void
    {
        DeviceConnectionRequest::factory()->create(['status' => 'pending']);
        DeviceConnectionRequest::factory()->rejected()->create();

        $rejectedRequests = DeviceConnectionRequest::rejected()->get();

        $this->assertCount(1, $rejectedRequests);
        $this->assertEquals('rejected', $rejectedRequests->first()->status);
    }

    /**
     * Test device_info is cast to array.
     */
    public function test_device_info_is_cast_to_array(): void
    {
        $deviceInfo = [
            'model' => 'iPhone 13',
            'os_version' => '15.0',
            'app_version' => '1.0.0',
        ];

        $request = DeviceConnectionRequest::factory()->create(['device_info' => $deviceInfo]);

        $this->assertIsArray($request->device_info);
        $this->assertEquals($deviceInfo, $request->device_info);
    }

    /**
     * Test approved_at is cast to datetime.
     */
    public function test_approved_at_is_cast_to_datetime(): void
    {
        $request = DeviceConnectionRequest::factory()->create(['approved_at' => now()]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $request->approved_at);
    }
}

