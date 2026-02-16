<?php

namespace Tests\Unit\Services;

use App\Events\AttendanceUpdated;
use App\Models\AttendanceSession;
use App\Models\AttendanceTracking;
use App\Models\Farm;
use App\Models\User;
use App\Services\AttendanceBoundaryDetectionService;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AttendanceBoundaryDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceBoundaryDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttendanceBoundaryDetectionService(
            new AttendanceService()
        );
    }

    /**
     * Test process GPS point creates session when user enters boundary.
     */
    public function test_process_gps_point_creates_session_when_user_enters_boundary(): void
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

        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $coordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $this->service->processGpsPoint($user, $coordinate, $dateTime);

        $session = AttendanceSession::where('user_id', $user->id)
            ->where('date', $dateTime->copy()->startOfDay())
            ->first();

        $this->assertNotNull($session);
        $this->assertNotNull($session->entry_time);
        $this->assertEquals('in_progress', $session->status);

        Event::assertDispatched(AttendanceUpdated::class);
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

        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $coordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $this->service->processGpsPoint($user, $coordinate, $dateTime);

        $session = AttendanceSession::where('user_id', $user->id)
            ->where('date', $dateTime->copy()->startOfDay())
            ->first();

        $this->assertNotNull($session);
        $this->assertGreaterThanOrEqual(0, $session->total_in_zone_duration);
    }

    /**
     * Test process GPS point closes session when user exits for >30 minutes.
     */
    public function test_process_gps_point_closes_session_when_user_exits_for_long_time(): void
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

        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $inBoundaryCoordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $entryTime = Carbon::now()->subMinutes(35);
        $this->service->processGpsPoint($user, $inBoundaryCoordinate, $entryTime);

        $coordinate = ['lat' => 35.7000, 'lng' => 51.4000, 'altitude' => 1200.5];
        $dateTime = Carbon::now();
        $this->service->processGpsPoint($user, $coordinate, $dateTime);

        $session = AttendanceSession::where('user_id', $user->id)
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
        $farm->coordinates = [];
        $farm->save();

        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $coordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $this->service->processGpsPoint($user, $coordinate, $dateTime);

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

        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $inBoundaryCoordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $entryTime = Carbon::now()->subMinutes(15);
        $this->service->processGpsPoint($user, $inBoundaryCoordinate, $entryTime);

        $coordinate = ['lat' => 35.7000, 'lng' => 51.4000, 'altitude' => 1200.5];
        $dateTime = Carbon::now();
        $this->service->processGpsPoint($user, $coordinate, $dateTime);

        $session = AttendanceSession::where('user_id', $user->id)
            ->whereDate('date', Carbon::today())
            ->first();

        $this->assertNotNull($session);
        $session->refresh();
        $this->assertEquals('in_progress', $session->status);
        $this->assertNull($session->exit_time);
    }

    /**
     * Test process GPS point does nothing when user has no attendance tracking.
     */
    public function test_process_gps_point_does_nothing_when_no_attendance_tracking(): void
    {
        $user = User::factory()->create();

        $coordinate = ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $this->service->processGpsPoint($user, $coordinate, $dateTime);

        $session = AttendanceSession::where('user_id', $user->id)->first();
        $this->assertNull($session);
    }
}
