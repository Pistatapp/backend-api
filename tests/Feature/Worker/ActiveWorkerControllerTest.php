<?php

namespace Tests\Feature\Worker;

use App\Models\Labour;
use App\Models\Farm;
use App\Models\User;
use App\Models\LabourGpsData;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveWorkerControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();
        $this->farm->coordinates = [
            [51.3890, 35.6892],
            [51.3890, 35.6900],
            [51.3900, 35.6900],
            [51.3900, 35.6892],
            [51.3890, 35.6892],
        ];
        $this->farm->save();
    }

    /**
     * Test index returns active workers.
     */
    public function test_index_returns_active_workers(): void
    {
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        LabourGpsData::factory()->create([
            'labour_id' => $labour->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/labours/active");

        $response->assertStatus(200);
    }

    /**
     * Test get path returns worker path for date.
     */
    public function test_get_path_returns_worker_path_for_date(): void
    {
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        $date = Carbon::parse('2024-11-15');

        LabourGpsData::factory()->create([
            'labour_id' => $labour->id,
            'date_time' => $date->copy()->setTime(8, 0, 0),
            'coordinate' => ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200],
        ]);

        LabourGpsData::factory()->create([
            'labour_id' => $labour->id,
            'date_time' => $date->copy()->setTime(12, 0, 0),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/labours/{$labour->id}/path?date={$date->toDateString()}");

        $response->assertStatus(200);
    }

    /**
     * Test get path uses today if date not provided.
     */
    public function test_get_path_uses_today_if_date_not_provided(): void
    {
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        LabourGpsData::factory()->create([
            'labour_id' => $labour->id,
            'date_time' => Carbon::today()->setTime(8, 0, 0),
            'coordinate' => ['lat' => 35.6892, 'lng' => 51.3890, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/labours/{$labour->id}/path");

        $response->assertStatus(200);
    }

    /**
     * Test get current status returns worker status.
     */
    public function test_get_current_status_returns_worker_status(): void
    {
        $labour = Labour::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        LabourGpsData::factory()->create([
            'labour_id' => $labour->id,
            'date_time' => Carbon::now()->subMinutes(5),
            'coordinate' => ['lat' => 35.6895, 'lng' => 51.3895, 'altitude' => 1200],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/labours/{$labour->id}/current-status");

        $response->assertStatus(200);
    }
}

