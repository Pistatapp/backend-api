<?php

namespace Tests\Unit\Models;

use App\Models\Labour;
use App\Models\LabourGpsData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class WorkerGpsDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that GPS data belongs to labour.
     */
    public function test_gps_data_belongs_to_employee(): void
    {
        $labour = Labour::factory()->create();
        $gpsData = LabourGpsData::factory()->create(['labour_id' => $labour->id]);

        $this->assertInstanceOf(Labour::class, $gpsData->labour);
        $this->assertEquals($labour->id, $gpsData->labour->id);
    }

    /**
     * Test that coordinate is cast to array.
     */
    public function test_coordinate_is_cast_to_array(): void
    {
        $coordinate = ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200.5];
        $gpsData = LabourGpsData::factory()->create(['coordinate' => $coordinate]);

        $this->assertIsArray($gpsData->coordinate);
        $this->assertEquals($coordinate, $gpsData->coordinate);
    }

    /**
     * Test that date_time is cast to datetime.
     */
    public function test_date_time_is_cast_to_datetime(): void
    {
        $dateTime = Carbon::now();
        $gpsData = LabourGpsData::factory()->create(['date_time' => $dateTime]);

        $this->assertInstanceOf(Carbon::class, $gpsData->date_time);
        $this->assertEquals($dateTime->format('Y-m-d H:i:s'), $gpsData->date_time->format('Y-m-d H:i:s'));
    }

    /**
     * Test that speed, bearing, and accuracy are cast to decimal.
     */
    public function test_numeric_fields_are_cast_to_decimal(): void
    {
        $gpsData = LabourGpsData::factory()->create([
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
        $labour = Labour::factory()->create();
        $coordinate = ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200.5];
        $dateTime = Carbon::now();

        $gpsData = LabourGpsData::factory()->create([
            'labour_id' => $labour->id,
            'coordinate' => $coordinate,
            'speed' => 0.0,
            'bearing' => 0.0,
            'accuracy' => 5.0,
            'provider' => 'gps',
            'date_time' => $dateTime,
        ]);

        $this->assertDatabaseHas('labour_gps_data', [
            'id' => $gpsData->id,
            'labour_id' => $labour->id,
            'provider' => 'gps',
        ]);
    }
}

