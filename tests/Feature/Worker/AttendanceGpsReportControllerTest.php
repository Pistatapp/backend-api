<?php

namespace Tests\Feature\Worker;

use App\Events\UserAttendanceStatusChanged;
use App\Models\AttendanceGpsData;
use App\Models\AttendanceTracking;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AttendanceGpsReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_gps_report_endpoint_stores_gps_data_successfully(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $farm = Farm::factory()->create();
        $farm->users()->attach($user->id, ['role' => 'operator', 'is_owner' => false]);
        $farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $farm->save();

        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/attendance/gps-report', [
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'altitude' => 1200.5,
            'speed' => 0.0,
            'bearing' => 0.0,
            'accuracy' => 5.0,
            'provider' => 'gps',
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('attendance_gps_data', [
            'user_id' => $user->id,
            'provider' => 'gps',
        ]);
    }

    public function test_gps_report_endpoint_returns_401_for_unauthenticated_user(): void
    {
        $response = $this->postJson('/api/attendance/gps-report', [
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(401);
    }

    public function test_gps_report_endpoint_returns_400_when_attendance_tracking_disabled(): void
    {
        $user = User::factory()->create();
        $farm = Farm::factory()->create();
        AttendanceTracking::create([
            'user_id' => $user->id,
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
            'enabled' => false,
        ]);

        $response = $this->actingAs($user)->postJson('/api/attendance/gps-report', [
            'latitude' => 35.6895,
            'longitude' => 51.3895,
            'time' => now()->getTimestampMs(),
        ]);

        $response->assertStatus(403);
    }
}
