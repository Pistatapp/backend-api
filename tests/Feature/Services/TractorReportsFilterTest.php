<?php

namespace Tests\Feature\Services;

use App\Models\GpsMetricsCalculation;
use App\Models\Operation;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Services\TractorReportFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TractorReportsFilterTest extends TestCase
{
    use RefreshDatabase;

    private TractorReportFilterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TractorReportFilterService::class);
    }

    #[Test]
    public function it_includes_operation_when_task_metrics_exist(): void
    {
        $tractor = Tractor::factory()->create();
        $operation = Operation::factory()->create(['farm_id' => $tractor->farm_id]);
        $gregorianDate = '2025-03-23';

        $task = TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'operation_id' => $operation->id,
            'date' => $gregorianDate,
            'status' => 'done',
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $task->id,
            'date' => $gregorianDate,
            'traveled_distance' => 100,
            'work_duration' => 3600,
            'average_speed' => 20,
        ]);

        $result = $this->service->filter([
            'tractor_id' => $tractor->id,
            'date' => '1404/01/03',
        ]);

        $report = $result['reports']->first();
        $this->assertSame($operation->id, $report['task']['operation']['id']);
        $this->assertSame($operation->name, $report['task']['operation']['name']);
    }

    #[Test]
    public function it_includes_operation_when_only_daily_aggregate_exists_but_tasks_are_defined(): void
    {
        $tractor = Tractor::factory()->create();
        $operation = Operation::factory()->create(['farm_id' => $tractor->farm_id]);
        $gregorianDate = '2025-03-23';

        TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'operation_id' => $operation->id,
            'date' => $gregorianDate,
            'status' => 'in_progress',
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => $gregorianDate,
            'traveled_distance' => 50,
            'work_duration' => 1800,
            'average_speed' => 15,
        ]);

        $result = $this->service->filter([
            'tractor_id' => $tractor->id,
            'date' => '1404/01/03',
        ]);

        $report = $result['reports']->first();
        $this->assertArrayHasKey('task', $report);
        $this->assertSame($operation->id, $report['task']['operation']['id']);
        $this->assertSame($operation->name, $report['task']['operation']['name']);
    }

    #[Test]
    public function it_excludes_daily_aggregate_when_task_metrics_exist_for_same_day(): void
    {
        $tractor = Tractor::factory()->create();
        $operation = Operation::factory()->create(['farm_id' => $tractor->farm_id]);
        $gregorianDate = '2025-03-23';

        $task = TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'operation_id' => $operation->id,
            'date' => $gregorianDate,
            'status' => 'done',
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => $gregorianDate,
            'traveled_distance' => 999,
            'work_duration' => 9999,
            'average_speed' => 99,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $task->id,
            'date' => $gregorianDate,
            'traveled_distance' => 100,
            'work_duration' => 3600,
            'average_speed' => 20,
        ]);

        $result = $this->service->filter([
            'tractor_id' => $tractor->id,
            'date' => '1404/01/03',
        ]);

        $this->assertCount(1, $result['reports']);
        $this->assertSame('100.00', $result['reports']->first()['traveled_distance']);
        $this->assertSame($operation->id, $result['reports']->first()['task']['operation']['id']);
    }
}
