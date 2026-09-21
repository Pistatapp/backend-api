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

    public function test_daily_efficiency_uses_actual_working_duration(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);

        $this->assertSame(
            56.25,
            app(TractorEfficiencyService::class)->calculate(
                $tractor,
                4 * 3600 + 30 * 60
            )
        );
    }

    public function test_daily_efficiency_is_100_percent_at_expected_working_duration(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);

        $this->assertSame(
            100.0,
            app(TractorEfficiencyService::class)->calculate($tractor, 8 * 3600)
        );
    }

    public function test_daily_efficiency_can_exceed_100_percent(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);

        $this->assertSame(
            125.0,
            app(TractorEfficiencyService::class)->calculate($tractor, 10 * 3600)
        );
    }

    public function test_task_efficiency_uses_working_duration_over_daily_expectation(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);
        $task = TractorTask::factory()->create(['tractor_id' => $tractor->id]);

        $total = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-09-05',
            'work_duration' => 6 * 3600,
            'stoppage_duration' => 2 * 3600,
        ]);
        $taskMetric = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2026-09-05',
            'work_duration' => 4 * 3600,
            'stoppage_duration' => 1 * 3600,
            'timings' => ['in_zone_duration_seconds' => 5 * 3600],
        ]);

        $service = app(TractorEfficiencyService::class);

        $this->assertSame(75.0, $service->calculate($tractor, $total->work_duration));
        $this->assertSame(
            50.0,
            $service->calculateTaskEfficiency($tractor, collect([$taskMetric]), $total)
        );
    }

    public function test_task_efficiency_excludes_stopped_time_inside_task_area(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);
        $task = TractorTask::factory()->create(['tractor_id' => $tractor->id]);

        $total = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-09-05',
            'work_duration' => 6 * 3600,
            'stoppage_duration' => 2 * 3600,
        ]);
        $taskMetric = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2026-09-05',
            'work_duration' => 3 * 3600,
            'stoppage_duration' => 2 * 3600,
            'timings' => ['in_zone_duration_seconds' => 5 * 3600],
        ]);

        $this->assertSame(
            37.5,
            app(TractorEfficiencyService::class)->calculateTaskEfficiency(
                $tractor,
                collect([$taskMetric]),
                $total
            )
        );
    }

    public function test_multiple_task_working_durations_are_capped_to_daily_working_duration(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);
        $firstTask = TractorTask::factory()->create(['tractor_id' => $tractor->id]);
        $secondTask = TractorTask::factory()->create(['tractor_id' => $tractor->id]);

        $total = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-09-05',
            'work_duration' => 6 * 3600,
            'stoppage_duration' => 0,
        ]);
        $firstMetric = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $firstTask->id,
            'date' => '2026-09-05',
            'work_duration' => 4 * 3600,
        ]);
        $secondMetric = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $secondTask->id,
            'date' => '2026-09-05',
            'work_duration' => 4 * 3600,
        ]);

        $this->assertSame(
            75.0,
            app(TractorEfficiencyService::class)->calculateTaskEfficiency(
                $tractor,
                collect([$firstMetric, $secondMetric]),
                $total
            )
        );
    }

    public function test_overlapping_task_working_intervals_are_counted_once_per_day(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);
        $firstTask = TractorTask::factory()->create(['tractor_id' => $tractor->id]);
        $secondTask = TractorTask::factory()->create(['tractor_id' => $tractor->id]);

        $total = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-09-05',
            'work_duration' => 3600,
            'stoppage_duration' => 0,
        ]);
        $firstMetric = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $firstTask->id,
            'date' => '2026-09-05',
            'work_duration' => 300,
            'timings' => [
                'working_intervals' => [['start' => 1000, 'end' => 1300]],
            ],
        ]);
        $secondMetric = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $secondTask->id,
            'date' => '2026-09-05',
            'work_duration' => 300,
            'timings' => [
                'working_intervals' => [['start' => 1200, 'end' => 1500]],
            ],
        ]);

        $efficiency = app(TractorEfficiencyService::class)->calculateTaskEfficiency(
            $tractor,
            collect([$firstMetric, $secondMetric]),
            $total
        );

        $this->assertEqualsWithDelta((500 / 28800) * 100, $efficiency, 0.000001);
    }

    public function test_zero_expected_work_returns_zero_without_division_by_zero(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 0]);

        $this->assertSame(
            0.0,
            app(TractorEfficiencyService::class)->calculate($tractor, 3600)
        );
    }

    public function test_null_expected_work_keeps_existing_default_without_crashing(): void
    {
        $tractor = new Tractor(['expected_daily_work_time' => null]);

        $this->assertSame(
            12.5,
            app(TractorEfficiencyService::class)->calculate($tractor, 3600)
        );
    }

    public function test_zero_actual_work_returns_zero_efficiency(): void
    {
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);

        $this->assertSame(
            0.0,
            app(TractorEfficiencyService::class)->calculate($tractor, 0)
        );
    }

    public function test_task_productivity_uses_working_duration_not_zone_presence(): void
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

        // Only 200 seconds of task working time count; zone presence and
        // stoppage duration are excluded from the numerator.
        $this->assertEqualsWithDelta((200 / 28800) * 100, $efficiency, 0.000001);
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
