<?php

namespace Tests\Feature\Worker;

use App\Models\AttendanceShiftSchedule;
use App\Models\AttendanceTracking;
use App\Models\Farm;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceShiftScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Role::where('name', 'admin')->exists()) {
            Role::create(['name' => 'admin']);
        }
        if (! Permission::where('name', 'view-farm-details')->exists()) {
            Permission::create(['name' => 'view-farm-details']);
        }
        $adminRole = Role::findByName('admin');
        if (! $adminRole->hasPermissionTo('view-farm-details')) {
            $adminRole->givePermissionTo('view-farm-details');
        }
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        $this->farm = Farm::factory()->create();
        $this->farm->users()->attach($this->user->id, ['role' => 'admin', 'is_owner' => false]);
    }

    public function test_index_returns_monthly_calendar(): void
    {
        if (! Role::where('name', 'labour')->exists()) {
            Role::create(['name' => 'labour']);
        }
        $workerUser = User::factory()->create();
        $workerUser->assignRole('labour');
        $workerUser->profile()->create(['name' => 'Worker']);
        $this->farm->users()->attach($workerUser->id, ['role' => 'labour', 'is_owner' => false]);
        AttendanceTracking::create([
            'user_id' => $workerUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create(['farm_id' => $this->farm->id]);
        $date = Carbon::parse('2024-11-15');
        AttendanceShiftSchedule::factory()->create([
            'user_id' => $workerUser->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/shift-schedules?month=11&year=2024");

        $response->assertStatus(200);
    }

    public function test_store_creates_new_shift_schedule(): void
    {
        $workerUser = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $workerUser->id,
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

        $date = Carbon::tomorrow();
        $shamsiDate = jdate($date)->format('Y/m/d');

        $response = $this->actingAs($this->user)
            ->postJson('/api/shift-schedules', [
                'user_id' => $workerUser->id,
                'shift_id' => $shift->id,
                'scheduled_dates' => [$shamsiDate],
            ]);

        $response->assertStatus(200);

        $schedule = AttendanceShiftSchedule::where('user_id', $workerUser->id)
            ->where('shift_id', $shift->id)
            ->whereDate('scheduled_date', $date)
            ->first();

        $this->assertNotNull($schedule);
        $this->assertEquals('scheduled', $schedule->status);
    }

    public function test_store_prevents_assignment_to_non_shift_based_user(): void
    {
        $workerUser = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $workerUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'administrative',
            'work_days' => [0, 1, 2, 3, 4],
            'work_hours' => 8,
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $shift = WorkShift::factory()->create(['farm_id' => $this->farm->id]);
        $shamsiDate = jdate(Carbon::tomorrow())->format('Y/m/d');

        $response = $this->actingAs($this->user)
            ->postJson('/api/shift-schedules', [
                'user_id' => $workerUser->id,
                'shift_id' => $shift->id,
                'scheduled_dates' => [$shamsiDate],
            ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'User must be shift-based to assign shifts']);
    }

    public function test_destroy_deletes_shift_schedule(): void
    {
        $workerUser = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $workerUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $schedule = AttendanceShiftSchedule::factory()->create([
            'user_id' => $workerUser->id,
            'shift_id' => WorkShift::factory()->create(['farm_id' => $this->farm->id])->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/shift-schedules/{$schedule->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('attendance_shift_schedules', ['id' => $schedule->id]);
    }
}
