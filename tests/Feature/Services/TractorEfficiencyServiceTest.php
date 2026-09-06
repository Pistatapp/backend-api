<?php

namespace Tests\Feature\Services;

use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Services\TractorEfficiencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TractorEfficiencyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_productivity_includes_stoppage_time(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);

        $this->assertSame(
            25.0,
            app(TractorEfficiencyService::class)->calculate(
                $tractor,
                app(TractorEfficiencyService::class)->observedWorkDurationSeconds(3600, 3600)
            )
        );
    }

    public function test_task_productivity_uses_zone_presence_and_is_capped_to_daily_observed_time(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);
        $task = TractorTask::factory()->create(['tractor_id' => $tractor->id]);

        $total = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-09-05',
            'work_duration' => 3600,
            'stoppage_duration' => 3600,
        ]);

        $firstTaskMetric = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2026-09-05',
            'work_duration' => 100,
            'stoppage_duration' => 100,
            'timings' => ['in_zone_duration_seconds' => 4000],
        ]);
        $secondTaskMetric = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => TractorTask::factory()->create(['tractor_id' => $tractor->id])->id,
            'date' => '2026-09-05',
            'work_duration' => 100,
            'stoppage_duration' => 100,
            'timings' => ['in_zone_duration_seconds' => 4000],
        ]);

        $efficiency = app(TractorEfficiencyService::class)->calculateTaskEfficiency(
            $tractor,
            collect([$firstTaskMetric, $secondTaskMetric]),
            $total
        );

        // 7,200 seconds of task rows are capped to the 7,200 seconds of
        // observed total work; 7,200 / 28,800 * 100 = 25%.
        $this->assertSame(25.0, $efficiency);
    }

    public function test_task_presence_falls_back_for_legacy_metrics_without_timing(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);
        $task = TractorTask::factory()->create(['tractor_id' => $tractor->id]);
        $metrics = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $task->id,
            'work_duration' => 1800,
            'stoppage_duration' => 900,
            'timings' => [],
        ]);

        $this->assertSame(
            2700,
            app(TractorEfficiencyService::class)->taskPresenceDurationSeconds($metrics)
        );
    }
}
