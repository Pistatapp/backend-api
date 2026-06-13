<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CalculateTaskGpsMetricsJob;
use App\Models\Field;
use App\Models\GpsMetricsCalculation;
use App\Models\Operation;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Models\User;
use App\Services\TaskGpsMetricsAnalyzer;
use App\Services\TractorTaskService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class CalculateTaskGpsMetricsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_uses_task_date_for_gps_window_when_task_date_is_in_the_past(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-13 18:00:00'));

        $task = $this->createTask(
            taskDate: '2026-06-01',
            startTime: '08:00:00',
            endTime: '10:00:00',
        );

        $analyzer = Mockery::mock(TaskGpsMetricsAnalyzer::class);
        $analyzer->shouldReceive('loadRecordsFor')
            ->once()
            ->withArgs(function (Tractor $tractor, Carbon $start, Carbon $end) use ($task) {
                return $tractor->id === $task->tractor_id
                    && $start->format('Y-m-d H:i:s') === '2026-06-01 08:00:00'
                    && $end->format('Y-m-d H:i:s') === '2026-06-01 10:00:00';
            })
            ->andReturnSelf();
        $analyzer->shouldReceive('analyze')
            ->once()
            ->andReturn($this->validInZoneResults());

        (new CalculateTaskGpsMetricsJob($task))->handle(
            $analyzer,
            app(TractorTaskService::class),
        );

        $task->refresh();
        $this->assertSame('done', $task->status);
    }

    public function test_marks_task_done_when_analyzer_finds_measurable_in_zone_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-13 18:00:00'));

        $task = $this->createTask(
            taskDate: '2026-06-05',
            startTime: '09:00:00',
            endTime: '12:00:00',
        );

        $analyzer = Mockery::mock(TaskGpsMetricsAnalyzer::class);
        $analyzer->shouldReceive('loadRecordsFor')->once()->andReturnSelf();
        $analyzer->shouldReceive('analyze')->once()->andReturn($this->validInZoneResults());

        (new CalculateTaskGpsMetricsJob($task))->handle(
            $analyzer,
            app(TractorTaskService::class),
        );

        $task->refresh();

        $this->assertSame('done', $task->status);
        $this->assertDatabaseHas('gps_metrics_calculations', [
            'tractor_id' => $task->tractor_id,
            'tractor_task_id' => $task->id,
            'work_duration' => 600,
            'traveled_distance' => 1.2,
        ]);
    }

    public function test_marks_task_not_done_when_analyzer_finds_no_in_zone_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-13 18:00:00'));

        $task = $this->createTask(
            taskDate: '2026-06-01',
            startTime: '08:00:00',
            endTime: '10:00:00',
        );

        $analyzer = Mockery::mock(TaskGpsMetricsAnalyzer::class);
        $analyzer->shouldReceive('loadRecordsFor')->once()->andReturnSelf();
        $analyzer->shouldReceive('analyze')->once()->andReturn([
            'movement_distance_km' => 0,
            'movement_duration_seconds' => 0,
            'stoppage_duration_seconds' => 0,
            'stoppage_count' => 0,
            'stoppage_duration_while_on_seconds' => 0,
            'stoppage_duration_while_off_seconds' => 0,
            'average_speed' => 0,
            'device_on_time' => null,
            'first_movement_time' => null,
            'has_zone_presence' => false,
        ]);

        (new CalculateTaskGpsMetricsJob($task))->handle(
            $analyzer,
            app(TractorTaskService::class),
        );

        $task->refresh();

        $this->assertSame('not_done', $task->status);
        $this->assertNull(GpsMetricsCalculation::where('tractor_task_id', $task->id)->first());
    }

    public function test_marks_task_done_when_analyzer_finds_stoppage_only_in_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-13 18:00:00'));

        $task = $this->createTask(
            taskDate: '2026-06-05',
            startTime: '09:00:00',
            endTime: '12:00:00',
        );

        $analyzer = Mockery::mock(TaskGpsMetricsAnalyzer::class);
        $analyzer->shouldReceive('loadRecordsFor')->once()->andReturnSelf();
        $analyzer->shouldReceive('analyze')->once()->andReturn([
            'movement_distance_km' => 0,
            'movement_duration_seconds' => 0,
            'stoppage_duration_seconds' => 120,
            'stoppage_count' => 1,
            'stoppage_duration_while_on_seconds' => 120,
            'stoppage_duration_while_off_seconds' => 0,
            'average_speed' => 0,
            'device_on_time' => '09:15:00',
            'first_movement_time' => null,
            'has_zone_presence' => true,
        ]);

        (new CalculateTaskGpsMetricsJob($task))->handle(
            $analyzer,
            app(TractorTaskService::class),
        );

        $task->refresh();

        $this->assertSame('done', $task->status);
        $this->assertDatabaseHas('gps_metrics_calculations', [
            'tractor_task_id' => $task->id,
            'stoppage_duration' => 120,
        ]);
    }

    private function createTask(string $taskDate, string $startTime, string $endTime): TractorTask
    {
        $tractor = Tractor::factory()->create();
        $field = Field::factory()->create(['farm_id' => $tractor->farm_id]);

        $task = TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'operation_id' => Operation::factory()->create()->id,
            'created_by' => User::factory()->create()->id,
            'date' => $taskDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => 'done',
        ]);

        $task->taskableItems()->delete();
        $task->syncTaskableItems(Field::class, [$field->id]);

        return $task->fresh(['tractor']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validInZoneResults(): array
    {
        return [
            'movement_distance_km' => 1.2,
            'movement_duration_seconds' => 600,
            'stoppage_duration_seconds' => 0,
            'stoppage_count' => 0,
            'stoppage_duration_while_on_seconds' => 0,
            'stoppage_duration_while_off_seconds' => 0,
            'average_speed' => 8,
            'device_on_time' => '09:00:00',
            'first_movement_time' => '09:05:00',
            'has_zone_presence' => true,
        ];
    }
}
