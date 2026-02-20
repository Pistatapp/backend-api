<?php

namespace Tests\Unit\Services;

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

    private ActiveUserAttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ActiveUserAttendanceService();
    }

    /**
     * Test get active users returns users with attendance tracking enabled
     */
    public function test_get_active_users_returns_users_with_attendance_tracking(): void
    {
        $farm = Farm::factory()->create();
        $farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $farm->save();

        $user1 = User::factory()->create();
        $user1->profile()->create(['name' => 'John Doe']);
        AttendanceTracking::create([
            'user_id' => $user1->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $user2 = User::factory()->create();
        $user2->profile()->create(['name' => 'Jane Smith']);
        AttendanceTracking::create([
            'user_id' => $user2->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $user1->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $user2->id,
            'date_time' => Carbon::now()->subHours(2),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $activeUsers = $this->service->getActiveUsers($farm);

        // Both users should be returned (no 10-minute filter)
        $this->assertCount(2, $activeUsers);
        $this->assertArrayHasKey('id', $activeUsers->first());
        $this->assertArrayHasKey('name', $activeUsers->first());
        $this->assertArrayHasKey('status', $activeUsers->first());
        $this->assertArrayHasKey('entrance_time', $activeUsers->first());
        $this->assertArrayHasKey('total_work_duration', $activeUsers->first());
    }

    /**
     * Test get active users returns empty collection when no users with tracking
     */
    public function test_get_active_users_returns_empty_collection_when_no_users(): void
    {
        $farm = Farm::factory()->create();

        $activeUsers = $this->service->getActiveUsers($farm);

        $this->assertCount(0, $activeUsers);
    }

    /**
     * Test get active users filters out disabled attendance tracking
     */
    public function test_get_active_users_filters_out_disabled_tracking(): void
    {
        $farm = Farm::factory()->create();
        $farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $farm->save();

        $enabledUser = User::factory()->create();
        $enabledUser->profile()->create(['name' => 'Enabled User']);
        AttendanceTracking::create([
            'user_id' => $enabledUser->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $disabledUser = User::factory()->create();
        $disabledUser->profile()->create(['name' => 'Disabled User']);
        AttendanceTracking::create([
            'user_id' => $disabledUser->id,
            'farm_id' => $farm->id,
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

        $activeUsers = $this->service->getActiveUsers($farm);

        $this->assertCount(1, $activeUsers);
        $this->assertEquals($enabledUser->id, $activeUsers->first()['id']);
    }

    /**
     * Test status calculation with shift schedule
     */
    public function test_status_calculation_with_shift_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

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
        $user->profile()->create(['name' => 'Test User']);
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
            'status' => 'scheduled',
        ]);

        // GPS inside zone during shift
        AttendanceGpsData::factory()->create([
            'user_id' => $user->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $activeUsers = $this->service->getActiveUsers($farm);

        $this->assertCount(1, $activeUsers);
        $this->assertEquals('present', $activeUsers->first()['status']);

        Carbon::setTestNow();
    }

    /**
     * Test entrance_time calculation
     */
    public function test_entrance_time_calculation(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

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
        $user->profile()->create(['name' => 'Test User']);
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::today(),
            'status' => 'scheduled',
        ]);

        // First GPS during shift
        AttendanceGpsData::factory()->create([
            'user_id' => $user->id,
            'date_time' => Carbon::parse('2024-01-15 08:30:00'),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $activeUsers = $this->service->getActiveUsers($farm);

        $this->assertCount(1, $activeUsers);
        $this->assertEquals('08:30', $activeUsers->first()['entrance_time']);

        Carbon::setTestNow();
    }
}
