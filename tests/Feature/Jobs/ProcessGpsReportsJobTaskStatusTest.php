<?php

namespace Tests\Feature\Jobs;

use App\Events\TractorTaskStatusChanged;
use App\Jobs\ProcessGpsReportsJob;
use App\Models\Field;
use App\Models\GpsDevice;
use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use App\Models\TractorTask;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProcessGpsReportsJobTaskStatusTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevice $device;
    private Tractor $tractor;
    private Field $field;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tractor = Tractor::factory()->create([
            'start_work_time' => '08:00',
            'end_work_time' => '16:00',
            'expected_daily_work_time' => 8,
        ]);

        $this->device = GpsDevice::factory()->create([
            'tractor_id' => $this->tractor->id,
        ]);

        // Create a field with known coordinates for zone testing
        $this->field = Field::factory()->create([
            'coordinates' => [
                [36.0, 51.0],
                [36.0, 51.1],
                [36.1, 51.1],
                [36.1, 51.0],
            ],
        ]);
    }

    /** @test */
    public function it_marks_task_as_in_progress_when_tractor_enters_zone()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 09:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        // GPS reports showing tractor inside the field zone
        $reports = [
            [
                'coordinate' => [36.05, 51.05], // Inside field
                'speed' => 5,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_stopped' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2025-10-08 09:00:00'),
                'imei' => $this->device->imei,
                'raw_data' => 'test',
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
        ];

        $job = new ProcessGpsReportsJob($this->device, $reports);
        $job->handle();

        $this->assertEquals('in_progress', $task->fresh()->status);
        Event::assertDispatched(TractorTaskStatusChanged::class);
    }

    /** @test */
    public function it_does_not_mark_task_as_in_progress_when_tractor_is_outside_zone()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 09:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        // GPS reports showing tractor outside the field zone
        $reports = [
            [
                'coordinate' => [40.0, 55.0], // Far outside field
                'speed' => 5,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_stopped' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2025-10-08 09:00:00'),
                'imei' => $this->device->imei,
                'raw_data' => 'test',
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
        ];

        $job = new ProcessGpsReportsJob($this->device, $reports);
        $job->handle();

        $this->assertEquals('not_started', $task->fresh()->status);
    }

    /** @test */
    public function it_updates_task_status_after_processing_gps_reports()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 13:00:00'); // After task end time

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'in_progress',
        ]);

        // Create existing GPS metrics showing sufficient presence (40%)
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2025-10-08',
            'work_duration' => 5760, // 40% of 4 hours (14400 seconds)
        ]);

        // Process some additional reports (won't affect existing metrics for this test)
        $reports = [
            [
                'coordinate' => [36.05, 51.05],
                'speed' => 0,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_stopped' => true,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2025-10-08 13:00:00'),
                'imei' => $this->device->imei,
                'raw_data' => 'test',
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
        ];

        $job = new ProcessGpsReportsJob($this->device, $reports);
        $job->handle();

        $this->assertEquals('done', $task->fresh()->status);
    }

    /** @test */
    public function it_handles_multiple_gps_reports_for_task()
    {
        Carbon::setTestNow('2025-10-08 09:30:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        // Multiple GPS reports showing movement in zone
        $reports = [
            [
                'coordinate' => [36.02, 51.02],
                'speed' => 5,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_stopped' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2025-10-08 09:00:00'),
                'imei' => $this->device->imei,
                'raw_data' => 'test1',
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
            [
                'coordinate' => [36.03, 51.03],
                'speed' => 6,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_stopped' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2025-10-08 09:15:00'),
                'imei' => $this->device->imei,
                'raw_data' => 'test2',
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
            [
                'coordinate' => [36.04, 51.04],
                'speed' => 7,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_stopped' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2025-10-08 09:30:00'),
                'imei' => $this->device->imei,
                'raw_data' => 'test3',
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
        ];

        $job = new ProcessGpsReportsJob($this->device, $reports);
        $job->handle();

        // Should be marked as in_progress since tractor is in zone
        $this->assertEquals('in_progress', $task->fresh()->status);

        // GPS metrics should be created for the task
        $metrics = GpsMetricsCalculation::where('tractor_task_id', $task->id)->first();
        $this->assertNotNull($metrics);
        $this->assertGreaterThan(0, $metrics->work_duration);
    }

    /** @test */
    public function it_does_not_update_task_status_when_no_active_task_exists()
    {
        Carbon::setTestNow('2025-10-08 09:00:00');

        // No task created for this tractor

        $reports = [
            [
                'coordinate' => [36.05, 51.05],
                'speed' => 5,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_stopped' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2025-10-08 09:00:00'),
                'imei' => $this->device->imei,
                'raw_data' => 'test',
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
        ];

        $job = new ProcessGpsReportsJob($this->device, $reports);

        // Should not throw exception when no task exists
        $job->handle();

        $this->assertTrue(true); // Test passes if no exception thrown
    }

    /** @test */
    public function it_processes_reports_during_task_time_and_marks_as_in_progress()
    {
        Event::fake();
        Carbon::setTestNow('2025-10-08 10:00:00'); // During task time

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $reports = [
            [
                'coordinate' => [36.05, 51.05], // Inside zone
                'speed' => 5,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_stopped' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2025-10-08 10:00:00'),
                'imei' => $this->device->imei,
                'raw_data' => 'test',
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
        ];

        $job = new ProcessGpsReportsJob($this->device, $reports);
        $job->handle();

        $this->assertEquals('in_progress', $task->fresh()->status);

        // Verify event was dispatched
        Event::assertDispatched(TractorTaskStatusChanged::class, function ($event) use ($task) {
            return $event->task->id === $task->id && $event->status === 'in_progress';
        });
    }

    /** @test */
    public function it_creates_separate_metrics_for_task_and_daily_summary()
    {
        Carbon::setTestNow('2025-10-08 09:00:00');

        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => Field::class,
            'taskable_id' => $this->field->id,
            'date' => '2025-10-08',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'not_started',
        ]);

        $reports = [
            [
                'coordinate' => [36.05, 51.05], // Inside zone
                'speed' => 5,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_stopped' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2025-10-08 09:00:00'),
                'imei' => $this->device->imei,
                'raw_data' => 'test',
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
        ];

        $job = new ProcessGpsReportsJob($this->device, $reports);
        $job->handle();

        // Should have task-specific metrics
        $taskMetrics = GpsMetricsCalculation::where('tractor_task_id', $task->id)->first();
        $this->assertNotNull($taskMetrics);

        // Should have daily summary metrics (null task_id)
        $dailyMetrics = GpsMetricsCalculation::where('tractor_id', $this->tractor->id)
            ->whereNull('tractor_task_id')
            ->whereDate('date', '2025-10-08')
            ->first();
        $this->assertNotNull($dailyMetrics);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // Reset Carbon mock
        parent::tearDown();
    }
}
