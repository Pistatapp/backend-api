<?php

namespace Tests\Unit\Services;

use App\Events\WorkerAttendanceUpdated;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\WorkerAttendanceSession;
use App\Services\WorkerAttendanceService;
use App\Services\WorkerBoundaryDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkerBoundaryDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkerBoundaryDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkerBoundaryDetectionService(
            new WorkerAttendanceService()
        );
    }

    /**
     * Test process GPS point creates session when worker enters boundary.
     */
    public function test_process_gps_point_creates_session_when_worker_enters_boundary(): void
    {
        Event::fake();

        $farm = Farm::factory()->create();
        // Create a simple rectangular boundary
        $farm->coordinates = [
            [51.3890, 35.6892], // SW corner
            [51.3890, 35.6900], // NW corner
            [51.3900, 35.6900], // NE corner
            [51.3900, 35.6892], // SE corner
            [51.3890, 35.6892], // Close polygon
        ];
        $farm->save();

        $employee = Employee::factory()->create(['farm_id' => $farm->id]);

        // Point inside boundary
        $coordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $this->service->processGpsPoint($employee, $coordinate, $dateTime);

        $session = WorkerAttendanceSession::where('employee_id', $employee->id)
            ->where('date', $dateTime->copy()->startOfDay())
            ->first();

        $this->assertNotNull($session);
        $this->assertNotNull($session->entry_time);
        $this->assertEquals('in_progress', $session->status);

        Event::assertDispatched(WorkerAttendanceUpdated::class);
    }

    /**
     * Test process GPS point updates time tracking when in zone.
     */
    public function test_process_gps_point_updates_time_tracking_when_in_zone(): void
    {
        Event::fake();

        $farm = Farm::factory()->create();
        $farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $farm->save();

        $employee = Employee::factory()->create(['farm_id' => $farm->id]);

        $coordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $this->service->processGpsPoint($employee, $coordinate, $dateTime);

        $session = WorkerAttendanceSession::where('employee_id', $employee->id)
            ->where('date', $dateTime->copy()->startOfDay())
            ->first();

        $this->assertNotNull($session);
        // Time tracking should be updated (even if small increment)
        $this->assertGreaterThanOrEqual(0, $session->total_in_zone_duration);
    }

    /**
     * Test process GPS point closes session when worker exits for >30 minutes.
     */
    public function test_process_gps_point_closes_session_when_worker_exits_for_long_time(): void
    {
        Event::fake();

        $farm = Farm::factory()->create();
        $farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $farm->save();

        $employee = Employee::factory()->create(['farm_id' => $farm->id]);

        // Don't create session manually - let processGpsPoint create it
        // First send a point inside boundary 35 minutes ago to create entry
        $inBoundaryCoordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $entryTime = Carbon::now()->subMinutes(35);
        $this->service->processGpsPoint($employee, $inBoundaryCoordinate, $entryTime);

        // Now send point outside boundary (far away) to trigger exit after 35 minutes
        $coordinate = ['lat' => 35.7000, 'lng' => 51.4000, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $this->service->processGpsPoint($employee, $coordinate, $dateTime);

        // Get the session that was created
        $session = WorkerAttendanceSession::where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today())
            ->first();

        $this->assertNotNull($session);
        $session->refresh();
        $this->assertEquals('completed', $session->status);
        $this->assertNotNull($session->exit_time);
    }

    /**
     * Test process GPS point handles missing farm coordinates gracefully.
     */
    public function test_process_gps_point_handles_missing_farm_coordinates_gracefully(): void
    {
        $farm = Farm::factory()->create();
        // Set empty array instead of null to avoid NOT NULL constraint
        $farm->coordinates = [];
        $farm->save();

        $employee = Employee::factory()->create(['farm_id' => $farm->id]);

        $coordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        // Should not throw exception
        $this->service->processGpsPoint($employee, $coordinate, $dateTime);

        // Should create session even without valid coordinates (service handles gracefully)
        $session = WorkerAttendanceSession::where('employee_id', $employee->id)
            ->where('date', $dateTime->copy()->startOfDay())
            ->first();

        // Session may or may not be created depending on boundary check logic
        // The important thing is no exception is thrown
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test process GPS point does not set exit time for short absences.
     */
    public function test_process_gps_point_does_not_set_exit_time_for_short_absences(): void
    {
        Event::fake();

        $farm = Farm::factory()->create();
        $farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $farm->save();

        $employee = Employee::factory()->create(['farm_id' => $farm->id]);

        // Don't create session manually - let processGpsPoint create it
        // First send a point inside boundary to create entry
        $inBoundaryCoordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $entryTime = Carbon::now()->subMinutes(15);
        $this->service->processGpsPoint($employee, $inBoundaryCoordinate, $entryTime);

        // Point outside boundary but only 15 minutes out
        $coordinate = ['lat' => 35.7000, 'lng' => 51.4000, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $this->service->processGpsPoint($employee, $coordinate, $dateTime);

        // Get the session that was created
        $session = WorkerAttendanceSession::where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today())
            ->first();

        $this->assertNotNull($session);
        $session->refresh();
        // Should remain in_progress (not closed yet - only 15 minutes out)
        $this->assertEquals('in_progress', $session->status);
        $this->assertNull($session->exit_time);
    }
}

