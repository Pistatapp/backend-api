<?php

namespace Tests\Unit\Jobs;

use App\Contracts\WeatherProvider;
use App\Jobs\CalculateColdRequirementJob;
use App\Jobs\CalculateGpsMetricsJob;
use App\Models\Farm;
use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use App\Models\User;
use App\Models\VolkOilSpray;
use App\Services\GpsDataAnalyzer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SchedulerQueueFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_gps_metrics_job_skips_when_daily_metrics_already_exist(): void
    {
        $tractor = Tractor::factory()->create();
        $date = Carbon::today();

        GpsMetricsCalculation::create([
            'tractor_id' => $tractor->id,
            'date' => $date->toDateString(),
            'tractor_task_id' => null,
            'traveled_distance' => 10,
            'work_duration' => 3600,
            'stoppage_count' => 0,
            'stoppage_duration' => 0,
            'average_speed' => 10,
            'efficiency' => 50,
        ]);

        $analyzer = Mockery::mock(GpsDataAnalyzer::class);
        $analyzer->shouldNotReceive('loadRecordsFor');

        $job = new CalculateGpsMetricsJob($tractor, $date);
        $job->handle($analyzer);
    }

    public function test_calculate_cold_requirement_job_marks_spray_as_checked(): void
    {
        $user = User::factory()->create();
        $farm = Farm::factory()->create();
        $spray = VolkOilSpray::create([
            'farm_id' => $farm->id,
            'start_dt' => now()->subDays(10)->toDateString(),
            'end_dt' => now()->subDay()->toDateString(),
            'min_temp' => 0,
            'max_temp' => 7,
            'cold_requirement' => 1000,
            'created_by' => $user->id,
        ]);

        $weatherMock = Mockery::mock(WeatherProvider::class);
        $weatherMock->shouldReceive('history')->once()->andReturn([
            'forecast' => [
                'forecastday' => [
                    ['hour' => [['temp_c' => 5]]],
                ],
            ],
        ]);
        $this->app->instance('weather-api', $weatherMock);

        $job = new CalculateColdRequirementJob($spray);
        $job->handle();

        $spray->refresh();
        $this->assertNotNull($spray->cold_requirement_checked_at);
    }
}
