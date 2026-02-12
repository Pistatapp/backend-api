<?php

namespace Tests\Feature;

use App\Models\AttendanceTracking;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserControllerAttendanceTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        // Create all required roles
        $roles = ['admin', 'operator', 'viewer', 'consultant', 'labour', 'employee'];
        foreach ($roles as $roleName) {
            if (!Role::where('name', $roleName)->exists()) {
                Role::create(['name' => $roleName]);
            }
        }

        // Create permission if it doesn't exist
        if (!Permission::where('name', 'manage-users')->exists()) {
            Permission::create(['name' => 'manage-users']);
        }

        // Assign permission to admin role
        $adminRole = Role::findByName('admin');
        if (!$adminRole->hasPermissionTo('manage-users')) {
            $adminRole->givePermissionTo('manage-users');
        }

        // Create admin user
        $this->adminUser = User::factory()->create([
            'mobile' => '09121111111',
        ]);
        $this->adminUser->assignRole('admin');

        // Create farm and attach admin user to it
        $this->farm = Farm::factory()->create();
        $this->farm->users()->attach($this->adminUser->id, [
            'role' => 'admin',
            'is_owner' => false,
        ]);
    }

    /**
     * Test creating a user with attendance tracking enabled (administrative work type).
     */
    public function test_can_create_user_with_attendance_tracking_administrative(): void
    {
        $userData = [
            'name' => 'John Doe',
            'mobile' => '09122222222',
            'role' => 'operator',
            'farm_id' => $this->farm->id,
            'attendence_tracking_enabled' => true,
            'work_type' => 'administrative',
            'work_days' => ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday'],
            'work_hours' => 8,
            'start_work_time' => '08:00',
            'end_work_time' => '16:00',
            'hourly_wage' => 150000,
            'overtime_hourly_wage' => 200000,
            'imei' => '123456789012345',
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/users', $userData);

        $response->assertStatus(201);

        // Verify user was created
        $user = User::where('mobile', '09122222222')->first();
        $this->assertNotNull($user);

        // Verify attendance tracking was created
        $this->assertDatabaseHas('attendance_trackings', [
            'user_id' => $user->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'administrative',
            'work_hours' => 8,
            'start_work_time' => '08:00',
            'end_work_time' => '16:00',
            'hourly_wage' => 150000,
            'overtime_hourly_wage' => 200000,
            'imei' => '123456789012345',
            'attendence_tracking_enabled' => 1,
        ]);

        // Verify work_days was stored as JSON array
        $attendanceTracking = AttendanceTracking::where('user_id', $user->id)->first();
        $this->assertNotNull($attendanceTracking);
        $this->assertEquals(['saturday', 'sunday', 'monday', 'tuesday', 'wednesday'], $attendanceTracking->work_days);
    }

    /**
     * Test creating a user with attendance tracking enabled (shift_based work type).
     */
    public function test_can_create_user_with_attendance_tracking_shift_based(): void
    {
        $userData = [
            'name' => 'Jane Smith',
            'mobile' => '09123333333',
            'role' => 'operator',
            'farm_id' => $this->farm->id,
            'attendence_tracking_enabled' => true,
            'work_type' => 'shift_based',
            'hourly_wage' => 180000,
            'overtime_hourly_wage' => 250000,
            'imei' => '987654321098765',
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/users', $userData);

        $response->assertStatus(201);

        // Verify user was created
        $user = User::where('mobile', '09123333333')->first();
        $this->assertNotNull($user);

        // Verify attendance tracking was created with shift_based work type
        $this->assertDatabaseHas('attendance_trackings', [
            'user_id' => $user->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 180000,
            'overtime_hourly_wage' => 250000,
            'imei' => '987654321098765',
            'attendence_tracking_enabled' => 1,
        ]);

        // Verify work_days, work_hours, start_work_time, end_work_time are null for shift_based
        $attendanceTracking = AttendanceTracking::where('user_id', $user->id)->first();
        $this->assertNotNull($attendanceTracking);
        $this->assertNull($attendanceTracking->work_days);
        $this->assertNull($attendanceTracking->work_hours);
        $this->assertNull($attendanceTracking->start_work_time);
        $this->assertNull($attendanceTracking->end_work_time);
    }

    /**
     * Test creating a user without attendance tracking enabled.
     */
    public function test_can_create_user_without_attendance_tracking(): void
    {
        $userData = [
            'name' => 'Bob Johnson',
            'mobile' => '09124444444',
            'role' => 'operator',
            'farm_id' => $this->farm->id,
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/users', $userData);

        $response->assertStatus(201);

        // Verify user was created
        $user = User::where('mobile', '09124444444')->first();
        $this->assertNotNull($user);

        // Verify attendance tracking was NOT created
        $this->assertDatabaseMissing('attendance_trackings', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Test updating a user to enable attendance tracking (creates new record).
     */
    public function test_can_update_user_to_enable_attendance_tracking(): void
    {
        // Create user without attendance tracking
        $user = User::factory()->create([
            'mobile' => '09125555555',
            'created_by' => $this->adminUser->id,
        ]);
        $user->assignRole('operator');
        $user->profile()->create(['name' => 'Alice Brown']);
        $user->farms()->attach($this->farm->id, [
            'role' => 'operator',
            'is_owner' => false,
        ]);

        $updateData = [
            'name' => 'Alice Brown',
            'mobile' => '09125555555',
            'role' => 'operator',
            'farm_id' => $this->farm->id,
            'attendence_tracking_enabled' => true,
            'work_type' => 'administrative',
            'work_days' => ['saturday', 'sunday'],
            'work_hours' => 6,
            'start_work_time' => '09:00',
            'end_work_time' => '15:00',
            'hourly_wage' => 120000,
            'overtime_hourly_wage' => 180000,
            'imei' => '111111111111111',
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/users/{$user->id}", $updateData);

        $response->assertStatus(200);

        // Verify attendance tracking was created
        $this->assertDatabaseHas('attendance_trackings', [
            'user_id' => $user->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'administrative',
            'work_hours' => 6,
            'start_work_time' => '09:00',
            'end_work_time' => '15:00',
            'hourly_wage' => 120000,
            'overtime_hourly_wage' => 180000,
            'imei' => '111111111111111',
            'attendence_tracking_enabled' => 1,
        ]);
    }

    /**
     * Test updating a user's existing attendance tracking record.
     */
    public function test_can_update_existing_attendance_tracking(): void
    {
        // Create user with attendance tracking
        $user = User::factory()->create([
            'mobile' => '09126666666',
            'created_by' => $this->adminUser->id,
        ]);
        $user->assignRole('operator');
        $user->profile()->create(['name' => 'Charlie Wilson']);
        $user->farms()->attach($this->farm->id, [
            'role' => 'operator',
            'is_owner' => false,
        ]);

        // Create initial attendance tracking
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'administrative',
            'work_days' => ['saturday'],
            'work_hours' => 4,
            'start_work_time' => '10:00',
            'end_work_time' => '14:00',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'imei' => '222222222222222',
            'attendence_tracking_enabled' => true,
        ]);

        $updateData = [
            'name' => 'Charlie Wilson',
            'mobile' => '09126666666',
            'role' => 'operator',
            'farm_id' => $this->farm->id,
            'attendence_tracking_enabled' => true,
            'work_type' => 'shift_based',
            'hourly_wage' => 200000,
            'overtime_hourly_wage' => 300000,
            'imei' => '333333333333333',
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/users/{$user->id}", $updateData);

        $response->assertStatus(200);

        // Verify attendance tracking was updated (not duplicated)
        $this->assertEquals(1, AttendanceTracking::where('user_id', $user->id)->count());

        // Verify updated values
        $attendanceTracking = AttendanceTracking::where('user_id', $user->id)->first();
        $this->assertEquals('shift_based', $attendanceTracking->work_type);
        $this->assertEquals($this->farm->id, $attendanceTracking->farm_id);
        $this->assertEquals(200000, $attendanceTracking->hourly_wage);
        $this->assertEquals(300000, $attendanceTracking->overtime_hourly_wage);
        $this->assertEquals('333333333333333', $attendanceTracking->imei);
        $this->assertTrue($attendanceTracking->attendence_tracking_enabled);
        
        // Verify administrative fields are cleared for shift_based
        $this->assertNull($attendanceTracking->work_days);
        $this->assertNull($attendanceTracking->work_hours);
        $this->assertNull($attendanceTracking->start_work_time);
        $this->assertNull($attendanceTracking->end_work_time);
    }

    /**
     * Test updating attendance tracking with different work type (administrative to shift_based).
     */
    public function test_can_update_attendance_tracking_work_type_change(): void
    {
        // Create user with administrative attendance tracking
        $user = User::factory()->create([
            'mobile' => '09127777777',
            'created_by' => $this->adminUser->id,
        ]);
        $user->assignRole('operator');
        $user->profile()->create(['name' => 'David Lee']);
        $user->farms()->attach($this->farm->id, [
            'role' => 'operator',
            'is_owner' => false,
        ]);

        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'administrative',
            'work_days' => ['saturday', 'sunday', 'monday'],
            'work_hours' => 8,
            'start_work_time' => '08:00',
            'end_work_time' => '16:00',
            'hourly_wage' => 150000,
            'overtime_hourly_wage' => 200000,
            'imei' => '444444444444444',
            'attendence_tracking_enabled' => true,
        ]);

        // Update to shift_based
        $updateData = [
            'name' => 'David Lee',
            'mobile' => '09127777777',
            'role' => 'operator',
            'farm_id' => $this->farm->id,
            'attendence_tracking_enabled' => true,
            'work_type' => 'shift_based',
            'hourly_wage' => 180000,
            'overtime_hourly_wage' => 250000,
            'imei' => '555555555555555',
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/users/{$user->id}", $updateData);

        $response->assertStatus(200);

        // Verify the record was updated
        $attendanceTracking = AttendanceTracking::where('user_id', $user->id)->first();
        $this->assertNotNull($attendanceTracking);
        $this->assertEquals('shift_based', $attendanceTracking->work_type);
        $this->assertEquals($this->farm->id, $attendanceTracking->farm_id);
        $this->assertNull($attendanceTracking->work_days, 'work_days should be null for shift_based');
        $this->assertNull($attendanceTracking->work_hours, 'work_hours should be null for shift_based');
        $this->assertNull($attendanceTracking->start_work_time, 'start_work_time should be null for shift_based');
        $this->assertNull($attendanceTracking->end_work_time, 'end_work_time should be null for shift_based');
    }

    /**
     * Test that farm_id is set correctly when creating attendance tracking.
     */
    public function test_farm_id_is_set_when_creating_attendance_tracking(): void
    {
        $userData = [
            'name' => 'Test User',
            'mobile' => '09120000000',
            'role' => 'operator',
            'farm_id' => $this->farm->id,
            'attendence_tracking_enabled' => true,
            'work_type' => 'administrative',
            'work_days' => ['saturday', 'sunday'],
            'work_hours' => 8,
            'start_work_time' => '08:00',
            'end_work_time' => '16:00',
            'hourly_wage' => 150000,
            'overtime_hourly_wage' => 200000,
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/users', $userData);

        $response->assertStatus(201);

        $user = User::where('mobile', '09120000000')->first();
        $attendanceTracking = AttendanceTracking::where('user_id', $user->id)->first();

        $this->assertNotNull($attendanceTracking);
        $this->assertEquals($this->farm->id, $attendanceTracking->farm_id);
    }

    /**
     * Test that farm_id is updated when updating attendance tracking with different farm_id.
     */
    public function test_farm_id_is_updated_when_changing_farm(): void
    {
        // Create a second farm
        $secondFarm = Farm::factory()->create();
        $secondFarm->users()->attach($this->adminUser->id, [
            'role' => 'admin',
            'is_owner' => false,
        ]);

        // Create user with attendance tracking
        $user = User::factory()->create([
            'mobile' => '09121111112',
            'created_by' => $this->adminUser->id,
        ]);
        $user->assignRole('operator');
        $user->profile()->create(['name' => 'Test User']);
        $user->farms()->attach($this->farm->id, [
            'role' => 'operator',
            'is_owner' => false,
        ]);

        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'administrative',
            'work_days' => ['saturday'],
            'work_hours' => 4,
            'start_work_time' => '10:00',
            'end_work_time' => '14:00',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'attendence_tracking_enabled' => true,
        ]);

        // Update user with different farm_id
        $updateData = [
            'name' => 'Test User',
            'mobile' => '09121111112',
            'role' => 'operator',
            'farm_id' => $secondFarm->id,
            'attendence_tracking_enabled' => true,
            'work_type' => 'administrative',
            'work_days' => ['saturday', 'sunday'],
            'work_hours' => 8,
            'start_work_time' => '08:00',
            'end_work_time' => '16:00',
            'hourly_wage' => 150000,
            'overtime_hourly_wage' => 200000,
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/users/{$user->id}", $updateData);

        $response->assertStatus(200);

        // Verify farm_id was updated
        $attendanceTracking = AttendanceTracking::where('user_id', $user->id)->first();
        $this->assertNotNull($attendanceTracking);
        $this->assertEquals($secondFarm->id, $attendanceTracking->farm_id);
    }

    /**
     * Test that attendance tracking is not created when attendence_tracking_enabled is false.
     */
    public function test_attendance_tracking_not_created_when_disabled(): void
    {
        $userData = [
            'name' => 'Eve Taylor',
            'mobile' => '09128888888',
            'role' => 'operator',
            'farm_id' => $this->farm->id,
            'attendence_tracking_enabled' => false,
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/users', $userData);

        $response->assertStatus(201);

        $user = User::where('mobile', '09128888888')->first();
        $this->assertNotNull($user);

        // Verify attendance tracking was NOT created
        $this->assertDatabaseMissing('attendance_trackings', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Test that only one attendance tracking record exists per user.
     */
    public function test_only_one_attendance_tracking_record_per_user(): void
    {
        $user = User::factory()->create([
            'mobile' => '09129999999',
            'created_by' => $this->adminUser->id,
        ]);
        $user->assignRole('operator');
        $user->profile()->create(['name' => 'Frank Miller']);
        $user->farms()->attach($this->farm->id, [
            'role' => 'operator',
            'is_owner' => false,
        ]);

        // Create initial attendance tracking
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'administrative',
            'work_days' => ['saturday'],
            'work_hours' => 4,
            'start_work_time' => '10:00',
            'end_work_time' => '14:00',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'imei' => '666666666666666',
            'attendence_tracking_enabled' => true,
        ]);

        // Update multiple times
        for ($i = 0; $i < 3; $i++) {
            $updateData = [
                'name' => 'Frank Miller',
                'mobile' => '09129999999',
                'role' => 'operator',
                'farm_id' => $this->farm->id,
                'attendence_tracking_enabled' => true,
                'work_type' => 'administrative',
                'work_days' => ['saturday', 'sunday'],
                'work_hours' => 8,
                'start_work_time' => '08:00',
                'end_work_time' => '16:00',
                'hourly_wage' => 150000 + ($i * 10000),
                'overtime_hourly_wage' => 200000 + ($i * 10000),
                'imei' => '777777777777777',
            ];

            $this->actingAs($this->adminUser, 'sanctum')
                ->putJson("/api/users/{$user->id}", $updateData)
                ->assertStatus(200);
        }

        // Verify only one record exists
        $this->assertEquals(1, AttendanceTracking::where('user_id', $user->id)->count());
    }
}
