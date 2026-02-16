<?php

namespace Tests\Unit\Services;

use App\Models\AttendanceShiftSchedule;
use App\Models\AttendanceTracking;
use App\Models\Farm;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\AttendanceWageCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceWageCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceWageCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttendanceWageCalculationService();
    }

    public function test_get_required_hours_for_shift_based_user(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift1 = WorkShift::factory()->create(['farm_id' => $farm->id, 'work_hours' => 4.0]);
        $shift2 = WorkShift::factory()->create(['farm_id' => $farm->id, 'work_hours' => 4.0]);
        $date = Carbon::today();

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_id' => $shift1->id,
            'scheduled_date' => $date->toDateString(),
            'status' => 'completed',
        ]);
        AttendanceShiftSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_id' => $shift2->id,
            'scheduled_date' => $date->toDateString(),
            'status' => 'completed',
        ]);

        $this->assertEquals(8.0, $this->service->getRequiredHours($user, $date));
    }

    public function test_get_required_hours_for_administrative_user_on_work_day(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'work_days' => [0, 1, 2, 3, 4],
            'work_hours' => 8.0,
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $this->assertEquals(0.0, $this->service->getRequiredHours($user, Carbon::parse('2024-11-15')));
        $this->assertEquals(8.0, $this->service->getRequiredHours($user, Carbon::parse('2024-11-11')));
    }

    public function test_get_required_hours_returns_0_for_administrative_user_on_non_work_day(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'work_days' => [0, 1, 2, 3, 4],
            'work_hours' => 8.0,
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $this->assertEquals(0.0, $this->service->getRequiredHours($user, Carbon::parse('2024-11-15')));
    }

    public function test_get_required_hours_for_shift_based_user_with_no_completed_shifts(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $this->assertEquals(0.0, $this->service->getRequiredHours($user, Carbon::today()));
    }

    public function test_calculate_base_wage(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $this->assertEquals(800000, $this->service->calculateBaseWage($user, 8.0));
    }

    public function test_calculate_overtime_wage(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'administrative',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $this->assertEquals(300000, $this->service->calculateOvertimeWage($user, 2.0));
    }

    public function test_get_required_hours_returns_0_when_no_attendance_tracking(): void
    {
        $user = User::factory()->create();
        $this->assertEquals(0.0, $this->service->getRequiredHours($user, Carbon::today()));
    }
}
