<?php

namespace Tests\Feature\Management;

use App\Models\Farm;
use App\Models\Irrigation;
use App\Models\Labour;
use App\Models\AttendanceShiftSchedule;
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
        $user = User::factory()->create();
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
            'user_id' => $user->id,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $user->id,
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
        $user = User::factory()->create();
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
            'user_id' => $user->id,
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

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $user->id,
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
     * Test can.delete is false when labour has irrigations.
     */
    public function test_can_delete_is_false_when_labour_has_irrigations(): void
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
            ->getJson("/api/labours/{$labour->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'can' => [
                    'update' => true,
                    'delete' => false,
                ],
            ],
        ]);
    }

    /**
     * Test can.delete is false when labour has shift schedules.
     */
    public function test_can_delete_is_false_when_labour_has_shift_schedules(): void
    {
        $user = User::factory()->create();
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
            'user_id' => $user->id,
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::tomorrow(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/labours/{$labour->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'can' => [
                    'update' => true,
                    'delete' => false,
                ],
            ],
        ]);
    }

    /**
     * Test can.delete is false when labour has both irrigations and shift schedules.
     */
    public function test_can_delete_is_false_when_labour_has_irrigations_and_shift_schedules(): void
    {
        $user = User::factory()->create();
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
            'user_id' => $user->id,
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

        AttendanceShiftSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'scheduled_date' => Carbon::tomorrow(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/labours/{$labour->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'can' => [
                    'update' => true,
                    'delete' => false,
                ],
            ],
        ]);
    }

    /**
     * Test can.delete is true when labour has no irrigations or shift schedules.
     */
    public function test_can_delete_is_true_when_labour_has_no_irrigations_or_shift_schedules(): void
    {
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/labours/{$labour->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'can' => [
                    'update' => true,
                    'delete' => true,
                ],
            ],
        ]);
    }

    /**
     * Test can.update is false when user doesn't have edit-worker permission.
     */
    public function test_can_update_is_false_when_user_lacks_permission(): void
    {
        // Create a role without edit-worker permission
        if (!Role::where('name', 'employee')->exists()) {
            Role::create(['name' => 'employee']);
        }

        // Create user without edit-worker permission
        /** @var User $userWithoutPermission */
        $userWithoutPermission = User::factory()->create();
        $userWithoutPermission->assignRole('employee');
        // Don't give edit-worker permission

        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        // Attach user to farm
        $this->farm->users()->attach($userWithoutPermission->id);

        $response = $this->actingAs($userWithoutPermission)
            ->getJson("/api/labours/{$labour->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'can' => [
                    'update' => false,
                    'delete' => false,
                ],
            ],
        ]);
    }

    /**
     * Test can.delete is false when user doesn't have edit-worker permission even without irrigations.
     */
    public function test_can_delete_is_false_when_user_lacks_permission_even_without_irrigations(): void
    {
        // Create a role without edit-worker permission
        if (!Role::where('name', 'employee')->exists()) {
            Role::create(['name' => 'employee']);
        }

        // Create user without edit-worker permission
        /** @var User $userWithoutPermission */
        $userWithoutPermission = User::factory()->create();
        $userWithoutPermission->assignRole('employee');
        // Don't give edit-worker permission

        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        // Attach user to farm
        $this->farm->users()->attach($userWithoutPermission->id);

        $response = $this->actingAs($userWithoutPermission)
            ->getJson("/api/labours/{$labour->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'can' => [
                    'update' => false,
                    'delete' => false,
                ],
            ],
        ]);
    }

    /**
     * Test can fields are correct in index response.
     */
    public function test_can_fields_are_correct_in_index_response(): void
    {
        $labourWithIrrigations = Labour::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        $labourWithoutIrrigations = Labour::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        $pump = Pump::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        Irrigation::factory()->create([
            'labour_id' => $labourWithIrrigations->id,
            'farm_id' => $this->farm->id,
            'pump_id' => $pump->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/labours");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);

        // Find labour with irrigations
        $labourWithIrrigationsData = collect($data)->firstWhere('id', $labourWithIrrigations->id);
        $this->assertNotNull($labourWithIrrigationsData);
        $this->assertFalse($labourWithIrrigationsData['can']['delete']);
        $this->assertTrue($labourWithIrrigationsData['can']['update']);

        // Find labour without irrigations
        $labourWithoutIrrigationsData = collect($data)->firstWhere('id', $labourWithoutIrrigations->id);
        $this->assertNotNull($labourWithoutIrrigationsData);
        $this->assertTrue($labourWithoutIrrigationsData['can']['delete']);
        $this->assertTrue($labourWithoutIrrigationsData['can']['update']);
    }
}
