<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceGpsData;
use App\Models\AttendanceSession;
use App\Models\AttendanceShiftSchedule;
use App\Models\AttendanceTracking;
use App\Models\Farm;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\ActiveUserAttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveUserAttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private ActiveUserAttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        $this->farm = Farm::factory()->create();
        $this->farm->coordinates = [
            [51.3890, 35.6892], // SW corner
            [51.3890, 35.6900], // NW corner
            [51.3900, 35.6900], // NE corner
            [51.3900, 35.6892], // SE corner
            [51.3890, 35.6892], // Close polygon
        ];
        $this->farm->save();

        // Attach user to farm
        $this->farm->users()->attach($this->user->id, [
            'role' => 'admin',
            'is_owner' => false,
        ]);

        $this->service = new ActiveUserAttendanceService();
    }

    /**
     * Test endpoint returns active users with correct structure
     */
    public function test_endpoint_returns_active_users_with_correct_structure(): void
    {
        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'John Doe']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'status', 'entry_time', 'work_duration']
            ]
        ]);
    }

    /**
     * Test endpoint filters out users with disabled attendance tracking
     */
    public function test_endpoint_filters_out_users_with_disabled_attendance_tracking(): void
    {
        $enabledUser = User::factory()->create();
        $enabledUser->profile()->create(['name' => 'Enabled User']);

        $disabledUser = User::factory()->create();
        $disabledUser->profile()->create(['name' => 'Disabled User']);

        AttendanceTracking::create([
            'user_id' => $enabledUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        AttendanceTracking::create([
            'user_id' => $disabledUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => false,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $enabledUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $disabledUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($enabledUser->id, $data[0]['id']);
    }

    /**
     * Test endpoint filters out users from different farms
     */
    public function test_endpoint_filters_out_users_from_different_farms(): void
    {
        $otherFarm = Farm::factory()->create();

        $userInFarm = User::factory()->create();
        $userInFarm->profile()->create(['name' => 'User In Farm']);

        $userInOtherFarm = User::factory()->create();
        $userInOtherFarm->profile()->create(['name' => 'User In Other Farm']);

        AttendanceTracking::create([
            'user_id' => $userInFarm->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        AttendanceTracking::create([
            'user_id' => $userInOtherFarm->id,
            'farm_id' => $otherFarm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $userInFarm->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $userInOtherFarm->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($userInFarm->id, $data[0]['id']);
    }

    /**
     * Test endpoint returns empty array when no active users
     */
    public function test_endpoint_returns_empty_array_when_no_active_users(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $response->assertJson(['data' => []]);
    }

    /**
     * Test endpoint requires authentication
     */
    public function test_endpoint_requires_authentication(): void
    {
        $response = $this->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertUnauthorized();
    }

    /**
     * Test status is "present" when user is in zone during shift
     */
    public function test_status_is_present_when_user_is_in_zone_during_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'Present User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $activeUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
            'status' => 'scheduled',
        ]);

        // GPS data inside zone during shift
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('present', $data[0]['status']);

        Carbon::setTestNow();
    }

    /**
     * Test status is "absent" when user is not in zone during shift
     */
    public function test_status_is_absent_when_user_is_not_in_zone_during_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'Absent User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $activeUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
            'status' => 'scheduled',
        ]);

        // GPS data outside zone during shift
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.7000, 51.4000],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('absent', $data[0]['status']);

        Carbon::setTestNow();
    }

    /**
     * Test status is "resting" when user is outside shift time
     */
    public function test_status_is_resting_when_user_is_outside_shift_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 18:00:00')); // After shift ends

        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'Resting User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $activeUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
            'status' => 'scheduled',
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('resting', $data[0]['status']);

        Carbon::setTestNow();
    }

    /**
     * Test status is resting when shift_based user has no shift schedule for the date
     */
    public function test_status_is_resting_when_shift_based_user_has_no_shift_schedule(): void
    {
        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'No Schedule User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('resting', $data[0]['status']);
        $this->assertEquals('00:00:00', $data[0]['entry_time']);
        $this->assertEquals(0, $data[0]['work_duration']);
    }

    /**
     * Test entry_time and work_duration come from attendance session
     */
    public function test_entry_time_and_work_duration_from_attendance_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'Entrance Test User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $activeUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
            'status' => 'scheduled',
        ]);

        AttendanceSession::factory()->create([
            'user_id' => $activeUser->id,
            'date' => Carbon::today(),
            'entry_time' => Carbon::parse('2024-01-15 08:30:00'),
            'in_zone_duration' => 90,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('08:30:00', $data[0]['entry_time']);
        $this->assertEquals(90, $data[0]['work_duration']);

        Carbon::setTestNow();
    }

    /**
     * Test entry_time is 00:00:00 and work_duration 0 when no attendance session
     */
    public function test_entry_time_and_work_duration_default_when_no_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'No Session User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $activeUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
            'status' => 'scheduled',
        ]);

        // No AttendanceSession for this user/date

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('00:00:00', $data[0]['entry_time']);
        $this->assertEquals(0, $data[0]['work_duration']);

        Carbon::setTestNow();
    }

    /**
     * Test work_duration from attendance session during shift
     */
    public function test_work_duration_from_attendance_session_during_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:30:00'));

        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'Duration Test User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $activeUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
            'status' => 'scheduled',
        ]);

        AttendanceSession::factory()->create([
            'user_id' => $activeUser->id,
            'date' => Carbon::today(),
            'entry_time' => Carbon::parse('2024-01-15 08:00:00'),
            'in_zone_duration' => 145,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('08:00:00', $data[0]['entry_time']);
        $this->assertEquals(145, $data[0]['work_duration']);

        Carbon::setTestNow();
    }

    /**
     * Test work_duration from attendance session when shift ended
     */
    public function test_work_duration_from_attendance_session_when_shift_ended(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 17:00:00'));

        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'Shift Ended User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $activeUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
            'status' => 'scheduled',
        ]);

        AttendanceSession::factory()->create([
            'user_id' => $activeUser->id,
            'date' => Carbon::today(),
            'entry_time' => Carbon::parse('2024-01-15 08:00:00'),
            'in_zone_duration' => 480,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('08:00:00', $data[0]['entry_time']);
        $this->assertEquals(480, $data[0]['work_duration']);

        Carbon::setTestNow();
    }

    /**
     * Test service returns latest GPS data regardless of time
     */
    public function test_service_returns_latest_gps_data_regardless_of_time(): void
    {
        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'Old GPS User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        // Old GPS data (more than 10 minutes ago)
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subHours(2),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        // User should still be returned (no 10-minute filter)
        $this->assertCount(1, $data);
        $this->assertEquals($activeUser->id, $data[0]['id']);
    }

    /**
     * Test midnight crossing shift (e.g., 22:00 - 02:00)
     */
    public function test_midnight_crossing_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 23:00:00')); // During night shift

        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'Night Shift User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '22:00:00',
            'end_time' => '02:00:00',
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $activeUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
            'status' => 'scheduled',
        ]);

        // Entrance at 22:00
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::parse('2024-01-15 22:00:00'),
            'coordinate' => [35.6895, 51.3895],
        ]);

        // Latest GPS
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        AttendanceSession::factory()->create([
            'user_id' => $activeUser->id,
            'date' => Carbon::today(),
            'entry_time' => Carbon::parse('2024-01-15 22:00:00'),
            'in_zone_duration' => 120,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('present', $data[0]['status']);
        $this->assertEquals('22:00:00', $data[0]['entry_time']);
        $this->assertEquals(120, $data[0]['work_duration']);

        Carbon::setTestNow();
    }

    /**
     * Test multiple users with different statuses
     */
    public function test_multiple_users_with_different_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        // Present user
        $presentUser = User::factory()->create();
        $presentUser->profile()->create(['name' => 'Present User']);
        AttendanceTracking::create([
            'user_id' => $presentUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);
        AttendanceShiftSchedule::factory()->create([
            'user_id' => $presentUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
        ]);
        AttendanceGpsData::factory()->create([
            'user_id' => $presentUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);
        AttendanceSession::factory()->create([
            'user_id' => $presentUser->id,
            'date' => Carbon::today(),
            'entry_time' => Carbon::parse('2024-01-15 08:00:00'),
            'in_zone_duration' => 120,
        ]);

        // Absent user
        $absentUser = User::factory()->create();
        $absentUser->profile()->create(['name' => 'Absent User']);
        AttendanceTracking::create([
            'user_id' => $absentUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);
        AttendanceShiftSchedule::factory()->create([
            'user_id' => $absentUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
        ]);
        AttendanceGpsData::factory()->create([
            'user_id' => $absentUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.7000, 51.4000],
        ]);
        AttendanceSession::factory()->create([
            'user_id' => $absentUser->id,
            'date' => Carbon::today(),
            'entry_time' => Carbon::parse('2024-01-15 08:15:00'),
            'in_zone_duration' => 30,
        ]);

        // No schedule user (no session)
        $noScheduleUser = User::factory()->create();
        $noScheduleUser->profile()->create(['name' => 'No Schedule User']);
        AttendanceTracking::create([
            'user_id' => $noScheduleUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);
        AttendanceGpsData::factory()->create([
            'user_id' => $noScheduleUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => [35.6895, 51.3895],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(3, $data);

        $presentData = collect($data)->firstWhere('id', $presentUser->id);
        $absentData = collect($data)->firstWhere('id', $absentUser->id);
        $noScheduleData = collect($data)->firstWhere('id', $noScheduleUser->id);

        $this->assertEquals('present', $presentData['status']);
        $this->assertEquals('08:00:00', $presentData['entry_time']);
        $this->assertEquals(120, $presentData['work_duration']);

        $this->assertEquals('absent', $absentData['status']);
        $this->assertEquals('08:15:00', $absentData['entry_time']);
        $this->assertEquals(30, $absentData['work_duration']);

        $this->assertEquals('resting', $noScheduleData['status']);
        $this->assertEquals('00:00:00', $noScheduleData['entry_time']);
        $this->assertEquals(0, $noScheduleData['work_duration']);

        Carbon::setTestNow();
    }
}
