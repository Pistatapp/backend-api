<?php

namespace Tests\Feature\Management;

use App\Models\Farm;
use App\Models\Irrigation;
use App\Models\Labour;
use App\Models\LabourShiftSchedule;
use App\Models\Pump;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LabourControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles if they don't exist
        if (!Role::where('name', 'admin')->exists()) {
            Role::create(['name' => 'admin']);
        }

        // Create permission if it doesn't exist
        if (!Permission::where('name', 'edit-worker')->exists()) {
            Permission::create(['name' => 'edit-worker']);
        }

        // Assign permission to admin role
        $adminRole = Role::findByName('admin');
        if (!$adminRole->hasPermissionTo('edit-worker')) {
            $adminRole->givePermissionTo('edit-worker');
        }

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $this->farm = Farm::factory()->create();
        $this->farm->users()->attach($this->user->id);
    }

    /**
     * Test user cannot delete labour when they have irrigations assigned.
     */
    public function test_user_cannot_delete_labour_with_irrigations(): void
    {
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        $pump = Pump::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        Irrigation::factory()->create([
            'labour_id' => $labour->id,
            'farm_id' => $this->farm->id,
            'pump_id' => $pump->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/labours/{$labour->id}");

        $response->assertStatus(403);

        // Verify labour still exists
        $this->assertDatabaseHas('labours', ['id' => $labour->id]);
    }

    /**
     * Test user cannot delete labour when they have shift schedules assigned.
     */
    public function test_user_cannot_delete_labour_with_shift_schedules(): void
    {
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        LabourShiftSchedule::factory()->create([
            'labour_id' => $labour->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::tomorrow(),
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/labours/{$labour->id}");

        $response->assertStatus(403);

        // Verify labour still exists
        $this->assertDatabaseHas('labours', ['id' => $labour->id]);
    }

    /**
     * Test user cannot delete labour when they have both irrigations and shift schedules assigned.
     */
    public function test_user_cannot_delete_labour_with_irrigations_and_shift_schedules(): void
    {
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
        ]);

        $pump = Pump::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        Irrigation::factory()->create([
            'labour_id' => $labour->id,
            'farm_id' => $this->farm->id,
            'pump_id' => $pump->id,
            'created_by' => $this->user->id,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        LabourShiftSchedule::factory()->create([
            'labour_id' => $labour->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::tomorrow(),
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/labours/{$labour->id}");

        $response->assertStatus(403);

        // Verify labour still exists
        $this->assertDatabaseHas('labours', ['id' => $labour->id]);
    }
}
