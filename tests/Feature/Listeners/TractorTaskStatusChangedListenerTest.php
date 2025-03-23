<?php

namespace Tests\Feature\Listeners;

use Tests\TestCase;
use App\Models\TractorTask;
use App\Models\Tractor;
use App\Models\Field;
use App\Models\Operation;
use App\Models\User;
use App\Models\GpsDailyReport;
use App\Events\TractorTaskStatusChanged;
use App\Notifications\TractorTaskStatusNotification;
use App\Listeners\TractorTaskStatusChangedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Event;

class TractorTaskStatusChangedListenerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tractor $tractor;
    private Field $field;
    private Operation $operation;
    private TractorTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tractor = Tractor::factory()->create();
        $this->field = Field::factory()->create();
        $this->operation = Operation::factory()->create(['name' => 'سم پاشی']);

        // Create task and persist it
        $this->task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'field_id' => $this->field->id,
            'operation_id' => $this->operation->id,
            'created_by' => $this->user->id,
            'date' => '2025-03-23',
            'start_time' => now()->subHours(4)->format('H:i'),
            'end_time' => now()->subHours(1)->format('H:i'),
        ]);
    }

    /** @test */
    public function it_sends_success_notification_when_task_completed_with_gps_data()
    {
        Notification::fake();

        // Create GPS daily report with movement data
        GpsDailyReport::create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $this->task->id,
            'date' => $this->task->date,
            'traveled_distance' => 5.5,
            'work_duration' => 12000, // 3 hours and 20 minutes
            'stoppage_count' => 2,
            'stoppage_duration' => 600,
            'efficiency' => 75.0
        ]);

        // Load necessary relationships
        $this->task->load(['tractor', 'operation', 'field', 'creator']);

        // Manually trigger event listener
        $listener = new TractorTaskStatusChangedListener();
        $event = new TractorTaskStatusChanged($this->task, 'finished');
        $listener->handle($event);

        Notification::assertSentTo(
            $this->user,
            TractorTaskStatusNotification::class,
        );
    }

    /** @test */
    public function it_sends_failure_notification_when_task_completed_without_gps_data()
    {
        Notification::fake();
        Event::fake([TractorTaskStatusChanged::class]);

        // Load necessary relationships
        $this->task->load(['tractor', 'operation', 'field', 'creator']);

        // Manually trigger event listener
        $listener = new TractorTaskStatusChangedListener();
        $event = new TractorTaskStatusChanged($this->task, 'finished');
        $listener->handle($event);

        Notification::assertSentTo(
            $this->user,
            TractorTaskStatusNotification::class,
        );
    }

    /** @test */
    public function it_sends_failure_notification_when_task_has_gps_report_but_no_movement()
    {
        Notification::fake();

        // Create GPS daily report with no movement
        GpsDailyReport::create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $this->task->id,
            'date' => $this->task->date,
            'traveled_distance' => 0,
            'work_duration' => 0,
            'stoppage_count' => 0,
            'stoppage_duration' => 0,
            'efficiency' => 0
        ]);

        // Load necessary relationships
        $this->task->load(['tractor', 'operation', 'field', 'creator']);

        // Manually trigger event listener
        $listener = new TractorTaskStatusChangedListener();
        $event = new TractorTaskStatusChanged($this->task, 'finished');
        $listener->handle($event);

        Notification::assertSentTo(
            $this->user,
            TractorTaskStatusNotification::class,
        );
    }

    /** @test */
    public function it_does_not_send_notification_for_non_finished_status()
    {
        Notification::fake();

        // Manually trigger event listener
        $listener = new TractorTaskStatusChangedListener();
        $event = new TractorTaskStatusChanged($this->task, 'started');
        $listener->handle($event);

        Notification::assertNothingSent();
    }

    /** @test */
    public function it_includes_correct_persian_date_in_message()
    {
        Notification::fake();

        $this->task->update(['date' => '2025-03-23']);
        $this->task->load(['tractor', 'operation', 'field', 'creator']);

        // Manually trigger event listener
        $listener = new TractorTaskStatusChangedListener();
        $event = new TractorTaskStatusChanged($this->task, 'finished');
        $listener->handle($event);

        Notification::assertSentTo(
            $this->user,
            TractorTaskStatusNotification::class,
            function ($notification) {
                $data = $notification->toArray($this->user);
                return str_contains($data['message'], '1404/01/03');
            }
        );
    }

    /** @test */
    public function it_does_not_throw_exceptions_when_sending_notification()
    {
        Notification::fake();
        Event::fake([TractorTaskStatusChanged::class]);

        // Create GPS daily report with valid data
        GpsDailyReport::create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $this->task->id,
            'date' => $this->task->date,
            'traveled_distance' => 10,
            'work_duration' => 3600, // 1 hour
            'stoppage_count' => 1,
            'stoppage_duration' => 300,
            'efficiency' => 85.0
        ]);

        // Manually trigger event listener
        $listener = new TractorTaskStatusChangedListener();
        $event = new TractorTaskStatusChanged($this->task, 'finished');

        $listener->handle($event);

        Notification::assertSentTo(
            $this->user,
            TractorTaskStatusNotification::class
        );
    }
}
