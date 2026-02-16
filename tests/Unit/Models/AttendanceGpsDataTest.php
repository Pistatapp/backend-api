<?php

namespace Tests\Unit\Models;

use App\Models\AttendanceGpsData;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceGpsDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that GPS data belongs to user.
     */
    public function test_gps_data_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $gpsData = AttendanceGpsData::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $gpsData->user);
        $this->assertEquals($user->id, $gpsData->user->id);
    }

    /**
     * Test that coordinate is cast to array.
     */
    public function test_coordinate_is_cast_to_array(): void
    {
        $coordinate = ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200.5];
        $gpsData = AttendanceGpsData::factory()->create(['coordinate' => $coordinate]);

        $this->assertIsArray($gpsData->coordinate);
        $this->assertEquals($coordinate, $gpsData->coordinate);
    }

    /**
     * Test that date_time is cast to datetime.
     */
    public function test_date_time_is_cast_to_datetime(): void
    {
        $dateTime = Carbon::now();
        $gpsData = AttendanceGpsData::factory()->create(['date_time' => $dateTime]);

        $this->assertInstanceOf(Carbon::class, $gpsData->date_time);
        $this->assertEquals($dateTime->format('Y-m-d H:i:s'), $gpsData->date_time->format('Y-m-d H:i:s'));
    }

    /**
     * Test that speed, bearing, and accuracy are cast to decimal.
     */
    public function test_numeric_fields_are_cast_to_decimal(): void
    {
        $gpsData = AttendanceGpsData::factory()->create([
            'speed' => 5.5,
            'bearing' => 90.25,
            'accuracy' => 10.75,
        ]);

        $this->assertIsNumeric($gpsData->speed);
        $this->assertIsNumeric($gpsData->bearing);
        $this->assertIsNumeric($gpsData->accuracy);
        $this->assertEquals(5.5, $gpsData->speed);
        $this->assertEquals(90.25, $gpsData->bearing);
        $this->assertEquals(10.75, $gpsData->accuracy);
    }

    /**
     * Test GPS data can be created with all required fields.
     */
    public function test_gps_data_can_be_created(): void
    {
        $user = User::factory()->create();
        $coordinate = ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $gpsData = AttendanceGpsData::factory()->create([
            'user_id' => $user->id,
            'coordinate' => $coordinate,
            'speed' => 0.0,
            'bearing' => 0.0,
            'accuracy' => 5.0,
            'provider' => 'gps',
            'date_time' => $dateTime,
        ]);

        $this->assertDatabaseHas('attendance_gps_data', [
            'id' => $gpsData->id,
            'user_id' => $user->id,
            'provider' => 'gps',
        ]);
    }
}
