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
        ]);
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $completedTask->id,
            'date' => '2026-09-04',
            'efficiency' => 12.5,
        ]);

        $chart = app(ActiveTractorService::class)->getWeeklyEfficiencyChart($tractor);
        $taskEfficiencyByDate = collect($chart['task_based_efficiencies'])->keyBy('date');

        $this->assertSame('0.00', $taskEfficiencyByDate->get(jdate('2026-09-03')->format('Y/m/d'))['efficiency']);
        $this->assertSame('12.50', $taskEfficiencyByDate->get(jdate('2026-09-04')->format('Y/m/d'))['efficiency']);
    }
}
