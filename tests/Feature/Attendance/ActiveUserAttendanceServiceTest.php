<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceGpsData;
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
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'status', 'entrance_time', 'total_work_duration']
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
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $disabledUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
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
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $userInOtherFarm->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
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
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
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
            'coordinate' => ['lat' => 35.7000, 'lng' => 51.4000, 'altitude' => 1200],
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
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
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
     * Test status is null when user has no shift schedule
     */
    public function test_status_is_null_when_user_has_no_shift_schedule(): void
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
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertNull($data[0]['status']);
        $this->assertNull($data[0]['entrance_time']);
        $this->assertNull($data[0]['total_work_duration']);
    }

    /**
     * Test entrance_time is calculated correctly from first GPS during shift
     */
    public function test_entrance_time_is_calculated_from_first_gps_during_shift(): void
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

        // First GPS during shift (entrance)
        $entranceTime = Carbon::parse('2024-01-15 08:30:00');
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => $entranceTime,
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // Later GPS during shift
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::parse('2024-01-15 09:00:00'),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // Latest GPS
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('08:30', $data[0]['entrance_time']);

        Carbon::setTestNow();
    }

    /**
     * Test entrance_time is null when no GPS data during shift
     */
    public function test_entrance_time_is_null_when_no_gps_during_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'No GPS User']);

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

        // GPS data before shift
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::parse('2024-01-15 07:00:00'),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertNull($data[0]['entrance_time']);
        $this->assertNull($data[0]['total_work_duration']);

        Carbon::setTestNow();
    }

    /**
     * Test total_work_duration is calculated correctly during shift
     */
    public function test_total_work_duration_is_calculated_during_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:30:00')); // 2.5 hours after entrance

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

        // Entrance at 08:00
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::parse('2024-01-15 08:00:00'),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // Latest GPS
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('08:00', $data[0]['entrance_time']);
        // Duration should be approximately 02:25 (2 hours 25 minutes from 08:00 to 10:25)
        $this->assertNotNull($data[0]['total_work_duration']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $data[0]['total_work_duration']);

        Carbon::setTestNow();
    }

    /**
     * Test total_work_duration uses shift end when shift has ended
     */
    public function test_total_work_duration_uses_shift_end_when_shift_ended(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 17:00:00')); // After shift ends at 16:00

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

        // Entrance at 08:00
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::parse('2024-01-15 08:00:00'),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // Latest GPS
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('08:00', $data[0]['entrance_time']);
        // Duration should be 08:00 (8 hours from 08:00 to 16:00)
        $this->assertEquals('08:00', $data[0]['total_work_duration']);

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
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
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
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // Latest GPS
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('present', $data[0]['status']);
        $this->assertEquals('22:00', $data[0]['entrance_time']);
        $this->assertNotNull($data[0]['total_work_duration']);

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
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
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
            'coordinate' => ['lat' => 35.7000, 'lng' => 51.4000, 'altitude' => 1200],
        ]);

        // No schedule user
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
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
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
        $this->assertEquals('absent', $absentData['status']);
        $this->assertNull($noScheduleData['status']);

        Carbon::setTestNow();
    }
}
