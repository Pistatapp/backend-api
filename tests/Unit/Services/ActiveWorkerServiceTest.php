<?php

namespace Tests\Unit\Services;

use App\Models\Labour;
use App\Models\Farm;
use App\Models\LabourGpsData;
use App\Services\ActiveLabourService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ActiveWorkerServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActiveLabourService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ActiveLabourService();
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

        $labour1 = Labour::factory()->create([
            'farm_id' => $farm->id,
            'name' => 'John Doe',
        ]);

        $labour2 = Labour::factory()->create([
            'farm_id' => $farm->id,
            'name' => 'Jane Smith',
        ]);

        // Labour with recent GPS data (5 minutes ago)
        LabourGpsData::factory()->create([
            'labour_id' => $labour1->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // Labour with old GPS data (15 minutes ago - not active)
        LabourGpsData::factory()->create([
            'labour_id' => $labour2->id,
            'date_time' => Carbon::now()->subMinutes(15),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $activeLabours = $this->service->getActiveLabours($farm);

        $this->assertCount(1, $activeLabours);
        $this->assertEquals($labour1->id, $activeLabours->first()['id']);
        $this->assertEquals('John Doe', $activeLabours->first()['name']);
    }

    /**
     * Test get active workers returns empty collection when no active workers.
     */
    public function test_get_active_workers_returns_empty_collection_when_no_active_workers(): void
    {
        $farm = Farm::factory()->create();

        $labour = Labour::factory()->create(['farm_id' => $farm->id]);

        // Only old GPS data
        LabourGpsData::factory()->create([
            'labour_id' => $labour->id,
            'date_time' => Carbon::now()->subMinutes(15),
        ]);

        $activeLabours = $this->service->getActiveLabours($farm);

        $this->assertCount(0, $activeLabours);
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

        $labour = Labour::factory()->create(['farm_id' => $farm->id]);
        
        LabourGpsData::factory()->create([
            'labour_id' => $labour->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // First call
        $activeLabours1 = $this->service->getActiveLabours($farm);

        // Delete GPS data
        LabourGpsData::where('labour_id', $labour->id)->delete();

        // Second call should return cached result
        $activeLabours2 = $this->service->getActiveLabours($farm);

        $this->assertEquals($activeLabours1->count(), $activeLabours2->count());
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

        $labour = Labour::factory()->create(['farm_id' => $farm->id]);
        
        LabourGpsData::factory()->create([
            'labour_id' => $labour->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        // Get and cache
        $this->service->getActiveLabours($farm);

        // Clear cache
        $this->service->clearCache($farm);

        // Delete GPS data
        LabourGpsData::where('labour_id', $labour->id)->delete();

        // Should now return empty (cache was cleared)
        $activeLabours = $this->service->getActiveLabours($farm);

        $this->assertCount(0, $activeLabours);
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

        $labour = Labour::factory()->create(['farm_id' => $farm->id]);
        
        // Point inside boundary
        LabourGpsData::factory()->create([
            'labour_id' => $labour->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $activeLabours = $this->service->getActiveLabours($farm);

        $this->assertTrue($activeLabours->first()['is_in_zone']);
    }
}

