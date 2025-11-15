<?php

namespace Tests\Unit\Services;

use App\Models\Employee;
use App\Models\Farm;
use App\Models\WorkerGpsData;
use App\Services\ActiveWorkerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ActiveWorkerServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActiveWorkerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ActiveWorkerService();
        Cache::flush();
    }

    /**
     * Test get active workers returns workers with recent GPS data.
     */
    public function test_get_active_workers_returns_workers_with_recent_gps_data(): void
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

        $employee1 = Employee::factory()->create([
            'farm_id' => $farm->id,
            'fname' => 'John',
            'lname' => 'Doe',
        ]);

        $employee2 = Employee::factory()->create([
            'farm_id' => $farm->id,
            'fname' => 'Jane',
            'lname' => 'Smith',
        ]);

        // Employee with recent GPS data (5 minutes ago)
        WorkerGpsData::factory()->create([
            'employee_id' => $employee1->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // Employee with old GPS data (15 minutes ago - not active)
        WorkerGpsData::factory()->create([
            'employee_id' => $employee2->id,
            'date_time' => Carbon::now()->subMinutes(15),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $activeWorkers = $this->service->getActiveWorkers($farm);

        $this->assertCount(1, $activeWorkers);
        $this->assertEquals($employee1->id, $activeWorkers->first()['id']);
        $this->assertEquals('John Doe', $activeWorkers->first()['name']);
    }

    /**
     * Test get active workers returns empty collection when no active workers.
     */
    public function test_get_active_workers_returns_empty_collection_when_no_active_workers(): void
    {
        $farm = Farm::factory()->create();

        $employee = Employee::factory()->create(['farm_id' => $farm->id]);

        // Only old GPS data
        WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => Carbon::now()->subMinutes(15),
        ]);

        $activeWorkers = $this->service->getActiveWorkers($farm);

        $this->assertCount(0, $activeWorkers);
    }

    /**
     * Test get active workers caches results.
     */
    public function test_get_active_workers_caches_results(): void
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

        $employee = Employee::factory()->create(['farm_id' => $farm->id]);
        
        WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // First call
        $activeWorkers1 = $this->service->getActiveWorkers($farm);

        // Delete GPS data
        WorkerGpsData::where('employee_id', $employee->id)->delete();

        // Second call should return cached result
        $activeWorkers2 = $this->service->getActiveWorkers($farm);

        $this->assertEquals($activeWorkers1->count(), $activeWorkers2->count());
    }

    /**
     * Test clear cache removes cached active workers.
     */
    public function test_clear_cache_removes_cached_active_workers(): void
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

        $employee = Employee::factory()->create(['farm_id' => $farm->id]);
        
        WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // Get and cache
        $this->service->getActiveWorkers($farm);

        // Clear cache
        $this->service->clearCache($farm);

        // Delete GPS data
        WorkerGpsData::where('employee_id', $employee->id)->delete();

        // Should now return empty (cache was cleared)
        $activeWorkers = $this->service->getActiveWorkers($farm);

        $this->assertCount(0, $activeWorkers);
    }

    /**
     * Test get active workers includes is_in_zone flag.
     */
    public function test_get_active_workers_includes_is_in_zone_flag(): void
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

        $employee = Employee::factory()->create(['farm_id' => $farm->id]);
        
        // Point inside boundary
        WorkerGpsData::factory()->create([
            'employee_id' => $employee->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $activeWorkers = $this->service->getActiveWorkers($farm);

        $this->assertTrue($activeWorkers->first()['is_in_zone']);
    }
}

