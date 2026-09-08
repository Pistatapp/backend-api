<?php

namespace Tests\Feature\Services;

use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Services\TractorReportFilterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TractorReportFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_summary_uses_effective_duration_once_and_task_rows_expose_zone_duration(): void
    {
        $date = Carbon::parse('2026-09-05');
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);
        $task = TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'date' => $date,
            'status' => 'done',
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => $date,
            'work_duration' => 3600,
            'stoppage_duration' => 3600,
            'stoppage_count' => 2,
            'timings' => [],
        ]);
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $task->id,
            'date' => $date,
            'work_duration' => 1800,
            'stoppage_duration' => 1800,
            'stoppage_count' => 1,
            'timings' => ['in_zone_duration_seconds' => 3600],
        ]);

        $result = app(TractorReportFilterService::class)->filter([
            'tractor_id' => $tractor->id,
            'date' => jdate($date)->format('Y/m/d'),
        ]);

        $this->assertSame('02:00:00', $result['expectations']['total_work_duration']);
        $this->assertSame('01:00:00', $result['accumulated']['work_duration']);
        $this->assertSame('02:00:00', $result['accumulated']['effective_work_duration']);
        $this->assertSame('01:00:00', $result['accumulated']['stoppage_duration']);
        $this->assertSame(2, $result['accumulated']['stoppage_count']);
        $this->assertSame('25.00', $result['expectations']['total_efficiency']);
        $this->assertSame('01:00:00', $result['expectations']['task_based_work_duration']);
        $this->assertSame('12.50', $result['expectations']['task_based_efficiency']);
        $this->assertCount(1, $result['reports']);
        $this->assertSame('01:00:00', $result['reports'][0]['work_duration']);
        $this->assertSame('02:00:00', $result['reports'][0]['effective_work_duration']);
        $this->assertSame('01:00:00', $result['reports'][0]['task_execution_duration']);
        $this->assertSame('12.50', $result['reports'][0]['task_efficiency']);
    }

    public function test_report_efficiency_is_not_capped_when_detail_exceeds_expected_time(): void
    {
        $date = Carbon::parse('2026-09-05');
        $tractor = Tractor::factory()->create(['expected_daily_work_time' => 8]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => $date,
            'work_duration' => 9 * 3600,
            'stoppage_duration' => 0,
            'stoppage_count' => 0,
            'timings' => [],
        ]);

        $result = app(TractorReportFilterService::class)->filter([
            'tractor_id' => $tractor->id,
            'date' => jdate($date)->format('Y/m/d'),
        ]);

        $this->assertSame('09:00:00', $result['expectations']['total_work_duration']);
        $this->assertSame('112.50', $result['expectations']['total_efficiency']);
    }
}
