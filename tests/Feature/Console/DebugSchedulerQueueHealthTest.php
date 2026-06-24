<?php

namespace Tests\Feature\Console;

use App\Listeners\ReportReceivedListener;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

class DebugSchedulerQueueHealthTest extends TestCase
{
    public function test_debug_scheduler_queue_health_command_runs(): void
    {
        $this->artisan('app:debug-scheduler-queue-health')
            ->assertSuccessful();
    }

    public function test_report_received_listener_is_queued_on_gps_processing(): void
    {
        $listener = new ReportReceivedListener(
            app(\App\Services\TractorTaskService::class)
        );

        $this->assertInstanceOf(ShouldQueue::class, $listener);
        $this->assertSame('gps-processing', $listener->queue);
    }
}
