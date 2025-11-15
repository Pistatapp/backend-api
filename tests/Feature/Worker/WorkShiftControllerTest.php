<?php

namespace Tests\Feature\Worker;

use App\Models\Farm;
use App\Models\WorkShift;
use App\Models\WorkerShiftSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkShiftControllerTest extends TestCase
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
     * Test index returns work shifts for farm.
     */
    public function test_index_returns_work_shifts_for_farm(): void
    {
        $shift1 = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Morning Shift',
            'start_time' => '08:00:00',
        ]);

        $shift2 = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Evening Shift',
            'start_time' => '16:00:00',
        ]);

        // Shift for different farm (should not be included)
        WorkShift::factory()->create([
            'farm_id' => Farm::factory()->create()->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/work-shifts");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    /**
     * Test store creates new work shift.
     */
    public function test_store_creates_new_work_shift(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/work-shifts", [
                'name' => 'Night Shift',
                'start_time' => '22:00',
                'end_time' => '06:00',
                'work_hours' => 8.0,
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Night Shift']);

        $this->assertDatabaseHas('work_shifts', [
            'farm_id' => $this->farm->id,
            'name' => 'Night Shift',
        ]);
    }

    /**
     * Test store validates required fields.
     */
    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/work-shifts", [
                // Missing required fields
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'start_time', 'end_time', 'work_hours']);
    }

    /**
     * Test store validates end_time is after start_time.
     */
    public function test_store_validates_end_time_is_after_start_time(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/work-shifts", [
                'name' => 'Invalid Shift',
                'start_time' => '16:00:00',
                'end_time' => '08:00:00', // Before start time
                'work_hours' => 8.0,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_time']);
    }

    /**
     * Test show returns single work shift.
     */
    public function test_show_returns_single_work_shift(): void
    {
        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Test Shift',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/work-shifts/{$shift->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Test Shift']);
    }

    /**
     * Test update modifies work shift.
     */
    public function test_update_modifies_work_shift(): void
    {
        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Original Name',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/work-shifts/{$shift->id}", [
                'name' => 'Updated Name',
                'work_hours' => 9.0,
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Updated Name']);

        $shift->refresh();
        $this->assertEquals('Updated Name', $shift->name);
        $this->assertEquals(9.0, $shift->work_hours);
    }

    /**
     * Test destroy deletes work shift.
     */
    public function test_destroy_deletes_work_shift(): void
    {
        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/work-shifts/{$shift->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('work_shifts', ['id' => $shift->id]);
    }

    /**
     * Test destroy prevents deletion when shift has scheduled workers.
     */
    public function test_destroy_prevents_deletion_when_shift_has_scheduled_workers(): void
    {
        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        WorkerShiftSchedule::factory()->create([
            'shift_id' => $shift->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/work-shifts/{$shift->id}");

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Cannot delete shift with scheduled workers']);

        $this->assertDatabaseHas('work_shifts', ['id' => $shift->id]);
    }
}

