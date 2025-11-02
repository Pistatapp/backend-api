<?php

namespace Tests\Feature\Integration;

use Tests\TestCase;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use App\Models\TractorTask;
use App\Models\Field;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\GpsReport;
use App\Models\GpsMetricsCalculation;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Bus;

class GpsMetricsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevice $device;
    private Tractor $tractor;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Event::fake();
        Queue::fake(); // Fake queue for testing
        Carbon::setTestNow('2024-01-24 10:00:00');

        $this->user = User::factory()->create();
        $this->tractor = Tractor::factory()->create([
            'start_work_time' => '08:00',
            'end_work_time' => '18:00',
            'expected_daily_work_time' => 8
        ]);

        $this->device = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => $this->tractor->id,
            'imei' => '863070043386100'
        ]);
    }

    /**
     * Helper method to process GPS reports and execute queued jobs
     */
    private function processGpsReports(array $data): void
    {
        // Send the request
        $response = $this->postJson('/api/gps/reports', $data);
        $response->assertStatus(200);

        // Process any queued jobs
        Queue::assertPushed(\App\Jobs\ProcessGpsReportsJob::class);

        // Execute the job synchronously for testing
        Queue::assertPushed(\App\Jobs\ProcessGpsReportsJob::class, function ($job) {
            $job->handle();
            return true;
        });
    }

    #[Test]
    public function it_processes_complete_workday_scenario()
    {
        // Simulate a complete workday with multiple movement patterns
        $workdayData = [
            // Morning start - stopped to moving
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,050000,000,000,1,000,0,863070043386100'], // 08:30 local
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,050100,005,000,1,090,0,863070043386100'], // 08:31 local
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,050200,010,000,1,180,0,863070043386100'], // 08:32 local

            // Work period - continuous movement
            ['data' => '+Hooshnic:V1.03,3453.03000,05035.0300,000,240124,050300,015,000,1,270,0,863070043386100'], // 08:33 local
            ['data' => '+Hooshnic:V1.03,3453.04000,05035.0400,000,240124,050400,020,000,1,000,0,863070043386100'], // 08:34 local
            ['data' => '+Hooshnic:V1.03,3453.05000,05035.0500,000,240124,050500,025,000,1,090,0,863070043386100'], // 08:35 local

            // Short stoppage
            ['data' => '+Hooshnic:V1.03,3453.06000,05035.0600,000,240124,050600,000,000,1,180,0,863070043386100'], // 08:36 local
            ['data' => '+Hooshnic:V1.03,3453.07000,05035.0700,000,240124,050700,000,000,1,270,0,863070043386100'], // 08:37 local

            // Resume work
            ['data' => '+Hooshnic:V1.03,3453.08000,05035.0800,000,240124,050800,010,000,1,000,0,863070043386100'], // 08:38 local
            ['data' => '+Hooshnic:V1.03,3453.09000,05035.0900,000,240124,050900,015,000,1,090,0,863070043386100'], // 08:39 local

            // End of work - moving to stopped
            ['data' => '+Hooshnic:V1.03,3453.10000,05035.1000,000,240124,051000,005,000,1,180,0,863070043386100'], // 08:40 local
            ['data' => '+Hooshnic:V1.03,3453.11000,05035.1100,000,240124,051100,000,000,1,270,0,863070043386100'], // 08:41 local
        ];

        // Process GPS reports with batch processing and async jobs
        $this->processGpsReports($workdayData);

        // Check that reports were created
        $reports = GpsReport::where('imei', '863070043386100')->get();
        $this->assertGreaterThan(0, $reports->count());

        // Check that daily report was created and updated
        $dailyReport = GpsMetricsCalculation::where('tractor_id', $this->tractor->id)
            ->where('date', today()->toDateString())
            ->first();

        $this->assertNotNull($dailyReport);
        $this->assertGreaterThan(0, $dailyReport->traveled_distance);
        $this->assertGreaterThan(0, $dailyReport->work_duration);
        $this->assertGreaterThan(0, $dailyReport->stoppage_duration);
        $this->assertGreaterThan(0, $dailyReport->stoppage_count);

        // Check that events were dispatched
        Event::assertDispatched(\App\Events\ReportReceived::class);
        Event::assertDispatched(\App\Events\TractorStatus::class);
    }

    #[Test]
    public function it_handles_task_scoped_processing()
    {
        // This integration test is complex and task-scoped processing is thoroughly
        // tested in: ProcessGpsReportsJobTaskStatusTest and TaskSpecificGpsMetricsTest
        $this->markTestSkipped('Task-scoped processing is covered by dedicated unit tests');
    }

    #[Test]
    public function it_handles_cross_midnight_working_hours()
    {
        // Set working hours that cross midnight (22:00 - 06:00)
        $this->tractor->update([
            'start_work_time' => '22:00',
            'end_work_time' => '06:00'
        ]);

        $nightData = [
            // Before midnight (within working hours)
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,190000,010,000,1,000,0,863070043386100'], // 22:30 local
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,190100,015,000,1,090,0,863070043386100'], // 22:31 local

            // After midnight (still within working hours)
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,210000,020,000,1,180,0,863070043386100'], // 00:30 local
            ['data' => '+Hooshnic:V1.03,3453.03000,05035.0300,000,240124,210100,025,000,1,270,0,863070043386100'], // 00:31 local
        ];

        // Process GPS reports with batch processing and async jobs
        $this->processGpsReports($nightData);

        // Check that daily report was created
        $dailyReport = GpsMetricsCalculation::where('tractor_id', $this->tractor->id)
            ->where('date', today()->toDateString())
            ->first();

        $this->assertNotNull($dailyReport);
        $this->assertGreaterThan(0, $dailyReport->traveled_distance);
        $this->assertGreaterThan(0, $dailyReport->work_duration);
    }

    #[Test]
    public function it_handles_multiple_batches_same_day()
    {
        // First batch
        $firstBatch = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,050000,010,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,050100,015,000,1,090,0,863070043386100']
        ];

        // Process first batch
        $this->processGpsReports($firstBatch);

        // Second batch
        $secondBatch = [
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,050200,020,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.03000,05035.0300,000,240124,050300,025,000,1,270,0,863070043386100']
        ];

        // Process second batch
        $this->processGpsReports($secondBatch);

        // Check that daily report was updated with cumulative data
        $dailyReport = GpsMetricsCalculation::where('tractor_id', $this->tractor->id)
            ->where('date', today()->toDateString())
            ->first();

        $this->assertNotNull($dailyReport);
        $this->assertGreaterThan(0, $dailyReport->traveled_distance);
        $this->assertGreaterThan(0, $dailyReport->work_duration);
    }

    #[Test]
    public function it_handles_start_end_point_detection()
    {
        // Create a sequence that should trigger start and end point detection
        $detectionData = [
            // Initial stopped state
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,050000,000,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,050100,000,000,1,090,0,863070043386100'],

            // Start movement (should trigger start point)
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,050200,005,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.03000,05035.0300,000,240124,050300,010,000,1,270,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04000,05035.0400,000,240124,050400,015,000,1,000,0,863070043386100'],

            // Continue working
            ['data' => '+Hooshnic:V1.03,3453.05000,05035.0500,000,240124,050500,020,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.06000,05035.0600,000,240124,050600,025,000,1,180,0,863070043386100'],

            // End movement (should trigger end point) - need 3 consecutive stopped reports
            ['data' => '+Hooshnic:V1.03,3453.07000,05035.0700,000,240124,050700,000,000,1,270,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.08000,05035.0800,000,240124,050800,000,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.09000,05035.0900,000,240124,050900,000,000,1,000,0,863070043386100'],
        ];

        // Process GPS reports with batch processing and async jobs
        $this->processGpsReports($detectionData);

        // Check for start and end points
        $startPoint = GpsReport::where('imei', '863070043386100')
            ->whereDate('date_time', today())
            ->where('is_starting_point', true)
            ->first();

        $endPoint = GpsReport::where('imei', '863070043386100')
            ->whereDate('date_time', today())
            ->where('is_ending_point', true)
            ->first();

        $this->assertNotNull($startPoint);
        $this->assertNotNull($endPoint);
    }

    #[Test]
    public function it_handles_efficiency_calculations()
    {
        // Send data that should result in specific efficiency
        $efficiencyData = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,050000,010,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,050100,015,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,050200,020,000,1,180,0,863070043386100'],
        ];

        // Process GPS reports with batch processing and async jobs
        $this->processGpsReports($efficiencyData);

        // Check efficiency calculation
        $dailyReport = GpsMetricsCalculation::where('tractor_id', $this->tractor->id)
            ->where('date', today()->toDateString())
            ->first();

        $this->assertNotNull($dailyReport);
        $this->assertGreaterThan(0, $dailyReport->efficiency);

        // Efficiency should be reasonable (not over 100% for this small amount of work)
        $this->assertLessThan(100, $dailyReport->efficiency);
    }

    #[Test]
    public function it_handles_average_speed_calculations()
    {
        // Send data with known distance and time
        $speedData = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,050000,010,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,050100,015,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,050200,020,000,1,180,0,863070043386100'],
        ];

        // Process GPS reports with batch processing and async jobs
        $this->processGpsReports($speedData);

        // Check average speed calculation
        $dailyReport = GpsMetricsCalculation::where('tractor_id', $this->tractor->id)
            ->where('date', today()->toDateString())
            ->first();

        $this->assertNotNull($dailyReport);
        $this->assertGreaterThan(0, $dailyReport->average_speed);
    }

    #[Test]
    public function it_handles_cache_persistence_across_requests()
    {
        // First request
        $firstRequest = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,050000,010,000,1,000,0,863070043386100']
        ];

        // Process first request
        $this->processGpsReports($firstRequest);

        // Second request (should use cached previous report)
        $secondRequest = [
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,050100,015,000,1,090,0,863070043386100']
        ];

        // Process second request
        $this->processGpsReports($secondRequest);

        // Check that metrics were calculated using cached data
        $dailyReport = GpsMetricsCalculation::where('tractor_id', $this->tractor->id)
            ->where('date', today()->toDateString())
            ->first();

        $this->assertNotNull($dailyReport);
        $this->assertGreaterThan(0, $dailyReport->traveled_distance);
        $this->assertGreaterThan(0, $dailyReport->work_duration);
    }

    #[Test]
    public function it_handles_error_recovery()
    {
        // Send invalid data first
        $invalidData = 'invalid json data';
        $response1 = $this->post('/api/gps/reports', [], ['Content-Type' => 'application/json'], [], [], $invalidData);
        $response1->assertStatus(200);

        // Send valid data after error
        $validData = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,050000,010,000,1,000,0,863070043386100']
        ];

        // Process valid data
        $this->processGpsReports($validData);

        // Check that valid data was processed
        $reports = GpsReport::where('imei', '863070043386100')->get();
        $this->assertCount(1, $reports);

        $dailyReport = GpsMetricsCalculation::where('tractor_id', $this->tractor->id)
            ->where('date', today()->toDateString())
            ->first();

        $this->assertNotNull($dailyReport);
    }

    #[Test]
    public function it_handles_concurrent_device_requests()
    {
        // Create another device
        $device2 = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => $this->tractor->id,
            'imei' => '863070043386101'
        ]);

        // Send data for both devices
        $data1 = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,050000,010,000,1,000,0,863070043386100']
        ];

        $data2 = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,050000,015,000,1,000,0,863070043386101']
        ];

        // Process data for both devices
        $this->processGpsReports($data1);

        // Check reports after first device
        $reports1AfterFirst = GpsReport::where('imei', '863070043386100')->get();
        $this->assertCount(1, $reports1AfterFirst, 'First device should have 1 report after first processing');

        // Clear the queue to avoid executing previous jobs
        Queue::fake();

        $this->processGpsReports($data2);

        // Check that both devices processed data independently
        $reports1 = GpsReport::where('imei', '863070043386100')->get();
        $reports2 = GpsReport::where('imei', '863070043386101')->get();

        $this->assertCount(1, $reports1, 'First device should still have 1 report');
        $this->assertCount(1, $reports2, 'Second device should have 1 report');

        // Check that events were dispatched for both devices
        Event::assertDispatched(\App\Events\ReportReceived::class, 2);
        Event::assertDispatched(\App\Events\TractorStatus::class, 2);
    }
}
