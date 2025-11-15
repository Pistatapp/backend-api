<?php

namespace Tests\Feature\Worker;

use App\Models\Employee;
use App\Models\Farm;
use App\Models\User;
use App\Models\WorkShift;
use App\Models\WorkerShiftSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerShiftScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();
    }

    /**
     * Test index returns monthly calendar.
     */
    public function test_index_returns_monthly_calendar(): void
    {
        $employee = Employee::factory()->create([
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
        ]);

        $shift = WorkShift::factory()->create(['farm_id' => $this->farm->id]);

        $date = Carbon::parse('2024-11-15');
        WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/shift-schedules?month=11&year=2024");

        $response->assertStatus(200);
    }

    /**
     * Test store creates new shift schedule.
     */
    public function test_store_creates_new_shift_schedule(): void
    {
        $employee = Employee::factory()->create([
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $date = Carbon::tomorrow();

        $response = $this->actingAs($this->user)
            ->postJson('/api/shift-schedules', [
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'scheduled_date' => $date->toDateString(),
            ]);

        $response->assertStatus(201);

        // Verify the schedule was created
        $schedule = \App\Models\WorkerShiftSchedule::where('employee_id', $employee->id)
            ->where('shift_id', $shift->id)
            ->whereDate('scheduled_date', $date)
            ->first();
        
        $this->assertNotNull($schedule);
        $this->assertEquals('scheduled', $schedule->status);
    }

    /**
     * Test store prevents assignment to non-shift-based employee.
     */
    public function test_store_prevents_assignment_to_non_shift_based_employee(): void
    {
        $employee = Employee::factory()->create([
            'farm_id' => $this->farm->id,
            'work_type' => 'administrative', // Not shift-based
        ]);

        $shift = WorkShift::factory()->create(['farm_id' => $this->farm->id]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/shift-schedules', [
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'scheduled_date' => Carbon::tomorrow()->toDateString(),
            ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Employee must be shift-based to assign shifts']);
    }

    /**
     * Test store prevents overlapping shifts.
     */
    public function test_store_prevents_overlapping_shifts(): void
    {
        $employee = Employee::factory()->create([
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
        ]);

        $existingShift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $newShift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '10:00:00', // Overlaps with existing
            'end_time' => '18:00:00',
        ]);

        $date = Carbon::tomorrow();

        // Create existing schedule
        WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $existingShift->id,
            'scheduled_date' => $date,
        ]);

        // Try to create overlapping schedule
        $response = $this->actingAs($this->user)
            ->postJson('/api/shift-schedules', [
                'employee_id' => $employee->id,
                'shift_id' => $newShift->id,
                'scheduled_date' => $date->toDateString(),
            ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Shift overlaps with existing schedule']);
    }

    /**
     * Test store allows non-overlapping shifts same day.
     */
    public function test_store_allows_non_overlapping_shifts_same_day(): void
    {
        $employee = Employee::factory()->create([
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
        ]);

        $morningShift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $eveningShift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'start_time' => '13:00:00',
            'end_time' => '17:00:00',
        ]);

        $date = Carbon::tomorrow();

        // Create morning schedule
        WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $morningShift->id,
            'scheduled_date' => $date,
        ]);

        // Should allow evening schedule (no overlap)
        $response = $this->actingAs($this->user)
            ->postJson('/api/shift-schedules', [
                'employee_id' => $employee->id,
                'shift_id' => $eveningShift->id,
                'scheduled_date' => $date->toDateString(),
            ]);

        $response->assertStatus(201);
    }

    /**
     * Test update modifies shift schedule.
     */
    public function test_update_modifies_shift_schedule(): void
    {
        $employee = Employee::factory()->create([
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
        ]);

        $shift = WorkShift::factory()->create(['farm_id' => $this->farm->id]);
        $schedule = WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/shift-schedules/{$schedule->id}", [
                'status' => 'completed',
            ]);

        $response->assertStatus(200);

        $schedule->refresh();
        $this->assertEquals('completed', $schedule->status);
    }

    /**
     * Test destroy deletes shift schedule.
     */
    public function test_destroy_deletes_shift_schedule(): void
    {
        $schedule = WorkerShiftSchedule::factory()->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/shift-schedules/{$schedule->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('worker_shift_schedules', ['id' => $schedule->id]);
    }
}

