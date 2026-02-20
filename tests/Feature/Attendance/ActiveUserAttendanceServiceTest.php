<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceTracking;
use App\Models\AttendanceGpsData;
use App\Models\Farm;
use App\Models\User;
use App\Services\ActiveUserAttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();
        $this->farm->coordinates = [
            [51.3890, 35.6892], // SW corner
            [51.3890, 35.6900], // NW corner
            [51.3900, 35.6900], // NE corner
            [51.3900, 35.6892], // SE corner
            [51.3890, 35.6892], // Close polygon
        ];
        $this->farm->save();

        $this->service = new ActiveUserAttendanceService();
        Cache::flush();
    }

    /**
     * Test endpoint returns active users with recent GPS data.
     */
    public function test_endpoint_returns_active_users_with_recent_gps_data(): void
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
                '*' => ['id', 'name', 'coordinate', 'last_update', 'is_in_zone']
            ]
        ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($activeUser->id, $data[0]['id']);
        $this->assertEquals('John Doe', $data[0]['name']);
        $this->assertTrue($data[0]['is_in_zone']);
    }

    /**
     * Test endpoint filters out users without recent GPS data.
     */
    public function test_endpoint_filters_out_users_without_recent_gps_data(): void
    {
        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'Active User']);

        $inactiveUser = User::factory()->create();
        $inactiveUser->profile()->create(['name' => 'Inactive User']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        AttendanceTracking::create([
            'user_id' => $inactiveUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        // Active user has GPS data within 10 minutes
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // Inactive user has GPS data older than 10 minutes
        AttendanceGpsData::factory()->create([
            'user_id' => $inactiveUser->id,
            'date_time' => Carbon::now()->subMinutes(15),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($activeUser->id, $data[0]['id']);
    }

    /**
     * Test endpoint filters out users with disabled attendance tracking.
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
     * Test endpoint filters out users from different farms.
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
     * Test endpoint returns empty array when no active users.
     */
    public function test_endpoint_returns_empty_array_when_no_active_users(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $response->assertJson(['data' => []]);
    }

    /**
     * Test endpoint requires authentication.
     */
    public function test_endpoint_requires_authentication(): void
    {
        $response = $this->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertUnauthorized();
    }

    /**
     * Test is_in_zone flag is correctly set when user is inside farm boundaries.
     */
    public function test_is_in_zone_flag_is_true_when_user_is_inside_farm_boundaries(): void
    {
        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'User In Zone']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        // Coordinate inside the farm polygon
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertTrue($data[0]['is_in_zone']);
    }

    /**
     * Test is_in_zone flag is false when user is outside farm boundaries.
     */
    public function test_is_in_zone_flag_is_false_when_user_is_outside_farm_boundaries(): void
    {
        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'User Outside Zone']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        // Coordinate outside the farm polygon
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.7000, 'lng' => 51.4000, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertFalse($data[0]['is_in_zone']);
    }

    /**
     * Test is_in_zone flag is false when user has no GPS data.
     */
    public function test_is_in_zone_flag_is_false_when_user_has_no_gps_data(): void
    {
        // This test verifies that users without GPS data are not returned as active
        // since the service filters by recent GPS data
        $user = User::factory()->create();
        $user->profile()->create(['name' => 'User Without GPS']);

        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        // No GPS data created

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(0, $data);
    }

    /**
     * Test endpoint caches results.
     */
    public function test_endpoint_caches_results(): void
    {
        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'Cached User']);

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

        // First request - should cache
        $response1 = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response1->assertStatus(200);
        $data1 = $response1->json('data');
        $this->assertCount(1, $data1);

        // Delete GPS data
        AttendanceGpsData::where('user_id', $activeUser->id)->delete();

        // Second request - should return cached data
        $response2 = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response2->assertStatus(200);
        $data2 = $response2->json('data');
        $this->assertCount(1, $data2);
        $this->assertEquals($data1[0]['id'], $data2[0]['id']);
    }

    /**
     * Test service clearCache method clears cached results.
     */
    public function test_service_clear_cache_clears_cached_results(): void
    {
        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'User To Clear']);

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

        // First request - should cache
        $response1 = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response1->assertStatus(200);
        $data1 = $response1->json('data');
        $this->assertCount(1, $data1);

        // Clear cache
        $this->service->clearCache($this->farm);

        // Delete GPS data
        AttendanceGpsData::where('user_id', $activeUser->id)->delete();

        // Second request - should return fresh data (empty)
        $response2 = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response2->assertStatus(200);
        $data2 = $response2->json('data');
        $this->assertCount(0, $data2);
    }

    /**
     * Test endpoint returns latest GPS data for each user.
     */
    public function test_endpoint_returns_latest_gps_data_for_each_user(): void
    {
        $activeUser = User::factory()->create();
        $activeUser->profile()->create(['name' => 'User With Multiple GPS']);

        AttendanceTracking::create([
            'user_id' => $activeUser->id,
            'farm_id' => $this->farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        // Older GPS data
        AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(8),
            'coordinate' => ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200],
        ]);

        // Latest GPS data
        $latestGps = AttendanceGpsData::factory()->create([
            'user_id' => $activeUser->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/farms/{$this->farm->id}/attendance/active-users");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($latestGps->coordinate['lat'], $data[0]['coordinate']['lat']);
        $this->assertEquals($latestGps->coordinate['lng'], $data[0]['coordinate']['lng']);
    }
}
