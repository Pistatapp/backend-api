<?php

namespace Tests\Unit\Models;

use App\Models\Employee;
use App\Models\WorkerGpsData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class WorkerGpsDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that GPS data belongs to employee.
     */
    public function test_gps_data_belongs_to_employee(): void
    {
        $employee = Employee::factory()->create();
        $gpsData = WorkerGpsData::factory()->create(['employee_id' => $employee->id]);

        $this->assertInstanceOf(Employee::class, $gpsData->employee);
        $this->assertEquals($employee->id, $gpsData->employee->id);
    }

    /**
     * Test that coordinate is cast to array.
     */
    public function test_coordinate_is_cast_to_array(): void
    {
        $coordinate = ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200.5];
        $gpsData = WorkerGpsData::factory()->create(['coordinate' => $coordinate]);

        $this->assertIsArray($gpsData->coordinate);
        $this->assertEquals($coordinate, $gpsData->coordinate);
    }

    /**
     * Test that date_time is cast to datetime.
     */
    public function test_date_time_is_cast_to_datetime(): void
    {
        $dateTime = Carbon::now();
        $gpsData = WorkerGpsData::factory()->create(['date_time' => $dateTime]);

        $this->assertInstanceOf(Carbon::class, $gpsData->date_time);
        $this->assertEquals($dateTime->format('Y-m-d H:i:s'), $gpsData->date_time->format('Y-m-d H:i:s'));
    }

    /**
     * Test that speed, bearing, and accuracy are cast to decimal.
     */
    public function test_numeric_fields_are_cast_to_decimal(): void
    {
        $gpsData = WorkerGpsData::factory()->create([
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
        $employee = Employee::factory()->create();
        $coordinate = ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $gpsData = WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'coordinate' => $coordinate,
            'speed' => 0.0,
            'bearing' => 0.0,
            'accuracy' => 5.0,
            'provider' => 'gps',
            'date_time' => $dateTime,
        ]);

        $this->assertDatabaseHas('worker_gps_data', [
            'id' => $gpsData->id,
            'employee_id' => $employee->id,
            'provider' => 'gps',
        ]);
    }
}

