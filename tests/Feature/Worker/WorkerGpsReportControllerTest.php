<?php

namespace Tests\Feature\Worker;

use App\Events\WorkerStatusChanged;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\User;
use App\Models\WorkerGpsData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WorkerGpsReportControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test GPS report endpoint stores GPS data successfully.
     */
    public function test_gps_report_endpoint_stores_gps_data_successfully(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'user_id' => $user->id,
        ]);

        // Mock farm coordinates for boundary detection
        $farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $farm->save();

        $response = $this->actingAs($user)->postJson('/api/workers/gps-report', [
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'altitude' => 1200.5,
            'speed' => 0.0,
            'bearing' => 0.0,
            'accuracy' => 5.0,
            'provider' => 'gps',
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('worker_gps_data', [
            'employee_id' => $employee->id,
            'provider' => 'gps',
        ]);

        Event::assertDispatched(WorkerStatusChanged::class);
    }

    /**
     * Test GPS report endpoint returns 401 for unauthenticated user.
     */
    public function test_gps_report_endpoint_returns_401_for_unauthenticated_user(): void
    {
        $response = $this->postJson('/api/workers/gps-report', [
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test GPS report endpoint returns 404 if employee not found.
     */
    public function test_gps_report_endpoint_returns_404_if_employee_not_found(): void
    {
        $user = User::factory()->create();
        // Don't create employee for this user

        $response = $this->actingAs($user)->postJson('/api/workers/gps-report', [
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Employee not found']);
    }

    /**
     * Test GPS report endpoint returns 400 for invalid GPS data.
     */
    public function test_gps_report_endpoint_returns_400_for_invalid_gps_data(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        // Missing required fields
        $response = $this->actingAs($user)->postJson('/api/workers/gps-report', [
            'latitude' => 35.6895,
            // Missing longitude and time
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid GPS data']);
    }

    /**
     * Test GPS report endpoint accepts optional fields.
     */
    public function test_gps_report_endpoint_accepts_optional_fields(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'user_id' => $user->id,
        ]);

        $farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $farm->save();

        // Only required fields
        $response = $this->actingAs($user)->postJson('/api/workers/gps-report', [
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(200);

        $gpsData = WorkerGpsData::where('employee_id', $employee->id)->first();
        $this->assertNotNull($gpsData);
        $this->assertEquals(0, $gpsData->altitude ?? 0);
        $this->assertEquals(0, $gpsData->speed);
        $this->assertEquals(0, $gpsData->bearing);
    }

    /**
     * Test GPS report endpoint handles errors gracefully.
     */
    public function test_gps_report_endpoint_handles_errors_gracefully(): void
    {
        Log::shouldReceive('warning')->atLeast()->once();

        $user = User::factory()->create();
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'user_id' => $user->id,
        ]);

        // Force an error by setting invalid coordinates format
        $farm->coordinates = [];
        $farm->save();

        $response = $this->actingAs($user)->postJson('/api/workers/gps-report', [
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        // Should still return 200 but log the error
        $response->assertStatus(200);
    }
}

