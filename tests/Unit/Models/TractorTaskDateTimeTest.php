<?php

namespace Tests\Unit\Models;

use App\Models\Field;
use App\Models\Operation;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TractorTaskDateTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_and_end_datetime_use_task_date_not_time_cast_anchor_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-13 12:00:00'));

        $tractor = Tractor::factory()->create();
        $task = TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'operation_id' => Operation::factory()->create()->id,
            'created_by' => User::factory()->create()->id,
            'date' => '2026-06-01',
            'start_time' => '08:30:00',
            'end_time' => '11:45:00',
            'status' => 'done',
        ]);

        $task->syncTaskableItems(Field::class, [
            Field::factory()->create(['farm_id' => $tractor->farm_id])->id,
        ]);

        $this->assertSame(
            '2026-06-01 08:30:00',
            $task->getStartDateTime()->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2026-06-01 11:45:00',
            $task->getEndDateTime()->format('Y-m-d H:i:s')
        );
    }

    public function test_end_datetime_crosses_midnight_when_end_time_is_before_start_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-13 12:00:00'));

        $tractor = Tractor::factory()->create();
        $task = TractorTask::factory()->create([
            'tractor_id' => $tractor->id,
            'operation_id' => Operation::factory()->create()->id,
            'created_by' => User::factory()->create()->id,
            'date' => '2026-06-10',
            'start_time' => '22:00:00',
            'end_time' => '02:00:00',
            'status' => 'done',
        ]);

        $this->assertSame(
            '2026-06-10 22:00:00',
            $task->getStartDateTime()->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2026-06-11 02:00:00',
            $task->getEndDateTime()->format('Y-m-d H:i:s')
        );
    }
}
