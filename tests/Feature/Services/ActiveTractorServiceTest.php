<?php

namespace Tests\Feature\Services;

use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use App\Services\ActiveTractorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveTractorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_task_based_chart_reads_task_metrics_including_zero_efficiency(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 12:00:00'));

        $tractor = Tractor::factory()->create();
        $failedTask = \App\Models\TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'date' => '2026-09-03',
            'status' => 'not_done',
        ]);
        $completedTask = \App\Models\TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'date' => '2026-09-04',
            'status' => 'done',
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $failedTask->id,
            'date' => '2026-09-03',
            'efficiency' => 0,
            'timings' => ['in_zone_duration_seconds' => 0],
        ]);
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $completedTask->id,
            'date' => '2026-09-04',
            'efficiency' => 12.5,
            'timings' => ['in_zone_duration_seconds' => 3600],
        ]);

        $chart = app(ActiveTractorService::class)->getWeeklyEfficiencyChart($tractor);
        $taskEfficiencyByDate = collect($chart['task_based_efficiencies'])->keyBy('date');

        $this->assertSame('0.00', $taskEfficiencyByDate->get(jdate('2026-09-03')->format('Y/m/d'))['efficiency']);
        $this->assertSame('12.50', $taskEfficiencyByDate->get(jdate('2026-09-04')->format('Y/m/d'))['efficiency']);
    }

    public function test_detail_productivity_uses_the_same_duration_basis_for_total_and_task(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 12:00:00'));

        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);
        $task = \App\Models\TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'date' => '2026-09-05',
            'status' => 'done',
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-09-05',
            'work_duration' => 3600,
            'stoppage_duration' => 3600,
            'timings' => [],
        ]);
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2026-09-05',
            'work_duration' => 1800,
            'stoppage_duration' => 1800,
            'efficiency' => 12.5,
            'timings' => ['in_zone_duration_seconds' => 3600],
        ]);

        $performance = app(ActiveTractorService::class)->getTractorPerformance(
            $tractor,
            Carbon::parse('2026-09-05')
        );

        $this->assertSame('25.00', $performance['efficiencies']['total']);
        $this->assertSame('12.50', $performance['efficiencies']['task-based']);
    }
}
