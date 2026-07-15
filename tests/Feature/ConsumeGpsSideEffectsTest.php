<?php

namespace Tests\Feature;

use App\Console\Commands\ConsumeGpsSideEffects;
use App\Listeners\ReportReceivedListener;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Services\TractorTaskService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ConsumeGpsSideEffectsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_process_payload_invokes_task_logic_for_go_message_shape(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-25 18:49:45'));

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        $device = GpsDevice::factory()->create(['tractor_id' => $tractor->id]);

        $task = TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'date' => '2026-02-25',
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
        ]);

        $taskService = Mockery::mock(TractorTaskService::class);
        $taskService->shouldReceive('getCurrentTask')
            ->once()
            ->andReturn($task);
        $taskService->shouldReceive('isPointInTaskZone')
            ->once()
            ->andReturn(true);
        $taskService->shouldReceive('updateTaskStatus')
            ->once()
            ->with($task, true, Mockery::type(Carbon::class));

        $this->app->instance(TractorTaskService::class, $taskService);

        $command = app(ConsumeGpsSideEffects::class);
        $processed = $command->processPayload([
            'device_id' => $device->id,
            'tractor_id' => $tractor->id,
            'device_imei' => $device->imei,
            'last_point' => [
                'coordinate' => [35.937893, 50.065403],
                'date_time' => '2026-02-25 18:49:45',
                'speed' => 0,
                'status' => 0,
                'directions' => ['ew' => 3, 'ns' => 1],
            ],
        ], app(ReportReceivedListener::class));

        $this->assertTrue($processed);
    }

    public function test_process_payload_returns_false_for_unknown_device(): void
    {
        $command = app(ConsumeGpsSideEffects::class);

        $processed = $command->processPayload([
            'device_id' => 99999,
            'last_point' => [
                'coordinate' => [35.937893, 50.065403],
                'date_time' => '2026-02-25 18:49:45',
                'speed' => 0,
                'status' => 0,
                'directions' => ['ew' => 3, 'ns' => 1],
            ],
        ], app(ReportReceivedListener::class));

        $this->assertFalse($processed);
    }
}
