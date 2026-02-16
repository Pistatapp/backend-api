<?php

namespace Tests\Unit\Services;

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

    private ActiveUserAttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ActiveUserAttendanceService();
        Cache::flush();
    }

    /**
     * Test get active users returns users with recent GPS data and attendance tracking.
     */
    public function test_get_active_users_returns_users_with_recent_gps_data(): void
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
            'date_time' => Carbon::now()->subMinutes(15),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $activeUsers = $this->service->getActiveUsers($farm);

        $this->assertCount(1, $activeUsers);
        $this->assertEquals($user1->id, $activeUsers->first()['id']);
        $this->assertEquals('John Doe', $activeUsers->first()['name']);
    }

    /**
     * Test get active users returns empty collection when no active users.
     */
    public function test_get_active_users_returns_empty_collection_when_no_active_users(): void
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

        AttendanceGpsData::factory()->create([
            'user_id' => $user->id,
            'date_time' => Carbon::now()->subMinutes(15),
        ]);

        $activeUsers = $this->service->getActiveUsers($farm);

        $this->assertCount(0, $activeUsers);
    }

    /**
     * Test get active users caches results.
     */
    public function test_get_active_users_caches_results(): void
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

        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $user->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $activeUsers1 = $this->service->getActiveUsers($farm);

        AttendanceGpsData::where('user_id', $user->id)->delete();

        $activeUsers2 = $this->service->getActiveUsers($farm);

        $this->assertEquals($activeUsers1->count(), $activeUsers2->count());
    }

    /**
     * Test clear cache removes cached active users.
     */
    public function test_clear_cache_removes_cached_active_users(): void
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

        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $user->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $this->service->getActiveUsers($farm);
        $this->service->clearCache($farm);

        AttendanceGpsData::where('user_id', $user->id)->delete();

        $activeUsers = $this->service->getActiveUsers($farm);

        $this->assertCount(0, $activeUsers);
    }

    /**
     * Test get active users includes is_in_zone flag.
     */
    public function test_get_active_users_includes_is_in_zone_flag(): void
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

        $user = User::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        AttendanceGpsData::factory()->create([
            'user_id' => $user->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $activeUsers = $this->service->getActiveUsers($farm);

        $this->assertTrue($activeUsers->first()['is_in_zone']);
    }
}
