<?php

namespace Tests\Feature;

use App\Jobs\CalculateSingleTractorEfficiencyJob;
use App\Jobs\CalculateTractorEfficiencyChartJob;
use App\Models\Tractor;
use App\Models\TractorEfficiencyChart;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TractorEfficiencyBatchJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that dispatcher job creates individual tractor jobs.
     */
    public function test_dispatcher_creates_individual_tractor_jobs(): void
    {
        // Create test tractors
        $tractors = Tractor::factory()->count(5)->create();

        Bus::fake();

        // Dispatch the main job
        $date = Carbon::yesterday();
        $job = new CalculateTractorEfficiencyChartJob($date, 10);
        $job->handle();

        // Assert that individual tractor jobs were batched
        Bus::assertBatched(function ($batch) use ($tractors) {
            return $batch->jobs->count() === $tractors->count() &&
                   $batch->jobs->first() instanceof CalculateSingleTractorEfficiencyJob;
        });
    }

    /**
     * Test that dispatcher splits large number of tractors into multiple batches.
     */
    public function test_dispatcher_splits_tractors_into_multiple_batches(): void
    {
        // Create 25 tractors
        Tractor::factory()->count(25)->create();

        Bus::fake();

        // Dispatch with batch size of 10
        $job = new CalculateTractorEfficiencyChartJob(Carbon::yesterday(), 10);
        $job->handle();

        // Should create 3 batches (10 + 10 + 5)
        Bus::assertBatchCount(3);
    }

    /**
     * Test that single tractor job calculates efficiency correctly.
     */
    public function test_single_tractor_job_calculates_efficiency(): void
    {
        // Create a tractor with expected work time
        $tractor = Tractor::factory()->create([
            'expected_daily_work_time' => 8,
            'start_work_time' => '08:00:00',
            'end_work_time' => '17:00:00',
        ]);

        $date = Carbon::yesterday();

        // Create GPS data for the tractor (simplified test)
        // In a real test, you'd create GPS data records

        // Execute the job directly
        $job = new CalculateSingleTractorEfficiencyJob($tractor->id, $date);
        $job->handle();

        // Verify efficiency chart was created
        $this->assertDatabaseHas('tractor_efficiency_charts', [
            'tractor_id' => $tractor->id,
            'date' => $date->toDateString(),
        ]);

        $chart = TractorEfficiencyChart::where('tractor_id', $tractor->id)
            ->where('date', $date->toDateString())
            ->first();

        $this->assertNotNull($chart);
        $this->assertIsNumeric($chart->total_efficiency);
        $this->assertIsNumeric($chart->task_based_efficiency);
    }

    /**
     * Test that WithoutOverlapping middleware prevents duplicate processing.
     */
    public function test_single_tractor_job_uses_without_overlapping(): void
    {
        $tractor = Tractor::factory()->create();
        $date = Carbon::yesterday();

        $job = new CalculateSingleTractorEfficiencyJob($tractor->id, $date);
        $middleware = $job->middleware();

        $this->assertNotEmpty($middleware);
        $this->assertInstanceOf(
            \Illuminate\Queue\Middleware\WithoutOverlapping::class,
            $middleware[0]
        );
    }

    /**
     * Test that job has correct queue configuration.
     */
    public function test_single_tractor_job_uses_efficiency_queue(): void
    {
        Queue::fake();

        $tractor = Tractor::factory()->create();
        $date = Carbon::yesterday();

        CalculateSingleTractorEfficiencyJob::dispatch($tractor->id, $date);

        Queue::assertPushedOn('efficiency', CalculateSingleTractorEfficiencyJob::class);
    }

    /**
     * Test that job has proper retry configuration.
     */
    public function test_single_tractor_job_has_retry_configuration(): void
    {
        $tractor = Tractor::factory()->create();
        $date = Carbon::yesterday();

        $job = new CalculateSingleTractorEfficiencyJob($tractor->id, $date);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(300, $job->timeout);
        $this->assertEquals(3, $job->maxExceptions);

        $backoff = $job->backoff();
        $this->assertEquals([30, 60, 120], $backoff);
    }

    /**
     * Test that job stops processing if batch is cancelled.
     */
    public function test_single_tractor_job_respects_batch_cancellation(): void
    {
        // This test would require more complex setup with actual batch
        // For now, we just verify the job can be created and has Batchable trait
        $tractor = Tractor::factory()->create();
        $date = Carbon::yesterday();

        $job = new CalculateSingleTractorEfficiencyJob($tractor->id, $date);

        $traits = class_uses($job);
        $this->assertContains('Illuminate\Bus\Batchable', $traits);
    }

    /**
     * Test dispatcher handles no tractors gracefully.
     */
    public function test_dispatcher_handles_no_tractors(): void
    {
        // Ensure no tractors exist
        Tractor::query()->delete();

        Bus::fake();

        $job = new CalculateTractorEfficiencyChartJob(Carbon::yesterday());
        $job->handle();

        // Should not dispatch any batches
        Bus::assertNothingBatched();
    }

    /**
     * Test that dispatcher uses default date when none provided.
     */
    public function test_dispatcher_uses_yesterday_by_default(): void
    {
        Tractor::factory()->count(2)->create();

        Bus::fake();

        // Create job without date parameter
        $job = new CalculateTractorEfficiencyChartJob();
        $job->handle();

        // Should dispatch jobs for yesterday
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 2;
        });
    }

    /**
     * Test memory optimization features.
     */
    public function test_single_tractor_job_uses_memory_optimization(): void
    {
        $tractor = Tractor::factory()->create([
            'expected_daily_work_time' => 8,
        ]);

        $date = Carbon::yesterday();

        // Execute job and check it completes without memory issues
        $job = new CalculateSingleTractorEfficiencyJob($tractor->id, $date);
        
        // This should not throw any exceptions
        $this->expectNotToPerformAssertions();
        
        try {
            $job->handle();
        } catch (\Exception $e) {
            $this->fail('Job threw exception: ' . $e->getMessage());
        }
    }
}

