<?php

namespace Tests\Unit\Services;

use App\Models\Employee;
use App\Models\WorkerGpsData;
use App\Services\WorkerPathService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WorkerPathServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkerPathService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkerPathService();
    }

    /**
     * Test get worker path returns GPS data for specific date.
     */
    public function test_get_worker_path_returns_gps_data_for_specific_date(): void
    {
        $employee = Employee::factory()->create();
        $date = Carbon::parse('2024-11-15');

        // GPS data for target date
        $gpsData1 = WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $date->copy()->setTime(8, 0, 0),
            'coordinate' => ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200],
        ]);

        $gpsData2 = WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $date->copy()->setTime(12, 0, 0),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // GPS data for different date (should not be included)
        WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $date->copy()->addDay()->setTime(8, 0, 0),
            'coordinate' => ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200],
        ]);

        $path = $this->service->getWorkerPath($employee, $date);

        $this->assertCount(2, $path);
        $this->assertEquals($gpsData1->id, $path->first()['id']);
        $this->assertEquals($gpsData2->id, $path->last()['id']);
    }

    /**
     * Test get worker path returns empty collection when no GPS data.
     */
    public function test_get_worker_path_returns_empty_collection_when_no_gps_data(): void
    {
        $employee = Employee::factory()->create();
        $date = Carbon::parse('2024-11-15');

        $path = $this->service->getWorkerPath($employee, $date);

        $this->assertCount(0, $path);
    }

    /**
     * Test get worker path orders points by time.
     */
    public function test_get_worker_path_orders_points_by_time(): void
    {
        $employee = Employee::factory()->create();
        $date = Carbon::parse('2024-11-15');

        // Create GPS data in reverse order
        $gpsData3 = WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $date->copy()->setTime(16, 0, 0),
            'coordinate' => ['lat' => 35.6900, 'lng' => 51.3900, 'altitude' => 1200],
        ]);

        $gpsData1 = WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $date->copy()->setTime(8, 0, 0),
            'coordinate' => ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200],
        ]);

        $gpsData2 = WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $date->copy()->setTime(12, 0, 0),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $path = $this->service->getWorkerPath($employee, $date);

        $this->assertEquals($gpsData1->id, $path->first()['id']);
        $this->assertEquals($gpsData2->id, $path->get(1)['id']);
        $this->assertEquals($gpsData3->id, $path->last()['id']);
    }

    /**
     * Test get worker path formats data correctly.
     */
    public function test_get_worker_path_formats_data_correctly(): void
    {
        $employee = Employee::factory()->create();
        $date = Carbon::parse('2024-11-15');

        $gpsData = WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => $date->copy()->setTime(8, 0, 0),
            'coordinate' => ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200],
            'speed' => 5.5,
            'bearing' => 90.25,
            'accuracy' => 10.75,
            'provider' => 'gps',
        ]);

        $path = $this->service->getWorkerPath($employee, $date);
        $point = $path->first();

        $this->assertArrayHasKey('id', $point);
        $this->assertArrayHasKey('coordinate', $point);
        $this->assertArrayHasKey('speed', $point);
        $this->assertArrayHasKey('bearing', $point);
        $this->assertArrayHasKey('accuracy', $point);
        $this->assertArrayHasKey('provider', $point);
        $this->assertArrayHasKey('date_time', $point);
        $this->assertEquals($gpsData->id, $point['id']);
        $this->assertEquals('gps', $point['provider']);
    }

    /**
     * Test get latest point returns most recent GPS data.
     */
    public function test_get_latest_point_returns_most_recent_gps_data(): void
    {
        $employee = Employee::factory()->create();

        $oldGpsData = WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => Carbon::now()->subHours(2),
            'coordinate' => ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200],
        ]);

        $latestGpsData = WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $latestPoint = $this->service->getLatestPoint($employee);

        $this->assertNotNull($latestPoint);
        $this->assertEquals($latestGpsData->id, $latestPoint['id']);
        $this->assertNotEquals($oldGpsData->id, $latestPoint['id']);
    }

    /**
     * Test get latest point returns null when no GPS data.
     */
    public function test_get_latest_point_returns_null_when_no_gps_data(): void
    {
        $employee = Employee::factory()->create();

        $latestPoint = $this->service->getLatestPoint($employee);

        $this->assertNull($latestPoint);
    }

    /**
     * Test get worker path handles errors gracefully.
     */
    public function test_get_worker_path_handles_errors_gracefully(): void
    {
        $employee = Employee::factory()->create();
        $date = Carbon::parse('2024-11-15');

        // Force an error by deleting employee's farm relationship
        $employee->farm_id = 99999; // Non-existent farm ID
        $employee->save();

        $path = $this->service->getWorkerPath($employee, $date);

        // Should return empty collection on error
        $this->assertCount(0, $path);
    }
}

