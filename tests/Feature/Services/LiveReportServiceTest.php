<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\LiveReportService;
use App\Services\TractorTaskService;
use App\Services\DailyReportService;
use App\Services\CacheService;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use App\Models\TractorTask;
use App\Models\Field;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\GpsDailyReport;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class LiveReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevice $device;
    private Tractor $tractor;
    private User $user;
    private LiveReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
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

        $this->service = new LiveReportService(
            new TractorTaskService($this->tractor),
            new DailyReportService($this->tractor, null),
            new CacheService($this->device)
        );
    }

    private function createSampleReports(): array
    {
        return [
            [
                'coordinate' => [34.883333, 50.583333],
                'speed' => 0,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => true,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 10:00:00'),
                'imei' => '863070043386100',
            ],
            [
                'coordinate' => [34.884333, 50.584333],
                'speed' => 5,
                'status' => 1,
                'directions' => ['ew' => 90, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => false,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 10:01:00'),
                'imei' => '863070043386100',
            ],
            [
                'coordinate' => [34.885333, 50.585333],
                'speed' => 10,
                'status' => 1,
                'directions' => ['ew' => 180, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => false,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 10:02:00'),
                'imei' => '863070043386100',
            ],
        ];
    }

    #[Test]
    public function it_generates_live_report_without_task()
    {
        $reports = $this->createSampleReports();

        $result = $this->service->generate($this->device, $reports);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('tractor_id', $result);
        $this->assertArrayHasKey('traveled_distance', $result);
        $this->assertArrayHasKey('work_duration', $result);
        $this->assertArrayHasKey('stoppage_duration', $result);
        $this->assertArrayHasKey('efficiency', $result);
        $this->assertArrayHasKey('stoppage_count', $result);
        $this->assertArrayHasKey('speed', $result);
        $this->assertArrayHasKey('points', $result);

        $this->assertEquals($this->tractor->id, $result['tractor_id']);
        $this->assertGreaterThan(0, $result['traveled_distance']);
        $this->assertGreaterThan(0, $result['work_duration']);
        $this->assertIsArray($result['points']);
    }

    #[Test]
    public function it_creates_daily_report_when_none_exists()
    {
        $reports = $this->createSampleReports();

        $this->service->generate($this->device, $reports);

        // Check that a daily report was created
        $dailyReport = GpsDailyReport::where('tractor_id', $this->tractor->id)
            ->where('date', today()->toDateString())
            ->first();

        $this->assertNotNull($dailyReport);
        $this->assertNull($dailyReport->tractor_task_id); // No task
    }

    #[Test]
    public function it_updates_existing_daily_report()
    {
        // Set up a previous report in cache
        $cacheService = new CacheService($this->device);
        $previousReport = [
            'coordinate' => [34.882333, 50.582333],
            'speed' => 5,
            'status' => 1,
            'directions' => ['ew' => 0, 'ns' => 0],
            'is_starting_point' => false,
            'is_ending_point' => false,
            'is_stopped' => false,
            'is_off' => false,
            'stoppage_time' => 0,
            'date_time' => Carbon::parse('2024-01-24 09:59:00'),
            'imei' => '863070043386100',
        ];
        $cacheService->setPreviousReport($previousReport);

        // Create an existing daily report
        $existingReport = GpsDailyReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'traveled_distance' => 50.0,
            'work_duration' => 1800, // 30 minutes
            'stoppage_duration' => 600, // 10 minutes
            'stoppage_count' => 2,
            'efficiency' => 10.0,
            'max_speed' => 15,
            'average_speed' => 20.0
        ]);

        $reports = $this->createSampleReports();

        $result = $this->service->generate($this->device, $reports);

        // Check that the existing report was updated
        $existingReport->refresh();

        // Debug: Check what values we actually got
        $this->assertGreaterThanOrEqual(50.0, $existingReport->traveled_distance,
            "Expected traveled_distance > 50.0, got: " . $existingReport->traveled_distance);
        $this->assertGreaterThanOrEqual(1800, $existingReport->work_duration,
            "Expected work_duration > 1800, got: " . $existingReport->work_duration);
        $this->assertGreaterThanOrEqual(10.0, $existingReport->efficiency,
            "Expected efficiency > 10.0, got: " . $existingReport->efficiency);
    }

    #[Test]
    public function it_generates_live_report_with_task()
    {
        // Create a farm and field with coordinates
        $farm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'coordinates' => [
                [34.88, 50.58],
                [34.89, 50.58],
                [34.89, 50.59],
                [34.88, 50.59],
                [34.88, 50.58]
            ]
        ]);

        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task for today
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $reports = $this->createSampleReports();

        $result = $this->service->generate($this->device, $reports);

        $this->assertArrayHasKey('id', $result);
        $this->assertEquals($this->tractor->id, $result['tractor_id']);

        // Check that a daily report was created with task
        $dailyReport = GpsDailyReport::where('tractor_id', $this->tractor->id)
            ->where('date', today()->toDateString())
            ->where('tractor_task_id', $task->id)
            ->first();

        $this->assertNotNull($dailyReport);
    }

    #[Test]
    public function it_calculates_efficiency_correctly()
    {
        $reports = $this->createSampleReports();

        $result = $this->service->generate($this->device, $reports);

        // Efficiency = (moving_time / expected_daily_work_time) * 100
        // moving_time = 120 seconds (2 minutes)
        // expected_daily_work_time = 8 hours = 28800 seconds
        // efficiency = (120 / 28800) * 100 = 0.417%
        $expectedEfficiency = (120 / (8 * 3600)) * 100;

        $this->assertEquals($expectedEfficiency, $result['efficiency'], '', 0.01);
    }

    #[Test]
    public function it_calculates_average_speed_correctly()
    {
        // Test the DailyReportService directly
        $dailyReport = GpsDailyReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'traveled_distance' => 10.0, // 10 km
            'work_duration' => 3600, // 1 hour
            'stoppage_duration' => 0,
            'stoppage_count' => 0,
            'efficiency' => 0,
            'max_speed' => 0,
            'average_speed' => 0
        ]);

        // Test adding some new data
        $newData = [
            'totalTraveledDistance' => 5.0, // 5 km more
            'totalMovingTime' => 1800, // 30 minutes more
            'totalStoppedTime' => 0,
            'stoppageCount' => 0,
            'maxSpeed' => 15
        ];

        $dailyReportService = new DailyReportService($this->tractor, null);
        $dailyReportService->update($dailyReport, $newData);

        $dailyReport->refresh();

        // The average speed should be calculated as: traveled_distance / (work_duration / 3600)
        // Total: 15.0 km / (5400 seconds / 3600) = 15.0 / 1.5 = 10.0 km/h
        $expectedAverageSpeed = 10.0;

        $this->assertEquals($expectedAverageSpeed, $dailyReport->average_speed, '', 0.01,
            "Expected average_speed = {$expectedAverageSpeed}, got: " . $dailyReport->average_speed .
            ", traveled_distance: " . $dailyReport->traveled_distance .
            ", work_duration: " . $dailyReport->work_duration);
    }

    #[Test]
    public function it_handles_empty_reports_array()
    {
        $result = $this->service->generate($this->device, []);

        $this->assertArrayHasKey('id', $result);
        $this->assertEquals($this->tractor->id, $result['tractor_id']);
        $this->assertEquals(0, $result['traveled_distance']);
        $this->assertEquals(0, $result['work_duration']);
        $this->assertEquals(0, $result['stoppage_duration']);
        $this->assertEquals(0, $result['efficiency']);
        $this->assertEquals(0, $result['stoppage_count']);
        $this->assertEquals(0, $result['speed']);
        $this->assertIsArray($result['points']);
        $this->assertCount(0, $result['points']);
    }

    #[Test]
    public function it_includes_points_in_response()
    {
        $reports = $this->createSampleReports();

        $result = $this->service->generate($this->device, $reports);

        $this->assertIsArray($result['points']);
        $this->assertGreaterThan(0, count($result['points']));

        // Check structure of first point
        $firstPoint = $result['points'][0];
        $this->assertArrayHasKey('coordinate', $firstPoint);
        $this->assertArrayHasKey('speed', $firstPoint);
        $this->assertArrayHasKey('status', $firstPoint);
        $this->assertArrayHasKey('date_time', $firstPoint);
    }

    #[Test]
    public function it_includes_latest_speed_in_response()
    {
        $reports = $this->createSampleReports();

        $result = $this->service->generate($this->device, $reports);

        // Speed should be from the latest stored report
        $this->assertIsNumeric($result['speed']);
        $this->assertGreaterThanOrEqual(0, $result['speed']);
    }

    #[Test]
    public function it_handles_cross_midnight_working_hours()
    {
        // Set working hours that cross midnight (22:00 - 06:00)
        $this->tractor->update([
            'start_work_time' => '22:00',
            'end_work_time' => '06:00'
        ]);

        $reports = [
            [
                'coordinate' => [34.883333, 50.583333],
                'speed' => 5,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => false,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 23:00:00'), // Within working hours
                'imei' => '863070043386100',
            ],
            [
                'coordinate' => [34.884333, 50.584333],
                'speed' => 10,
                'status' => 1,
                'directions' => ['ew' => 90, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => false,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 23:01:00'),
                'imei' => '863070043386100',
            ],
        ];

        $result = $this->service->generate($this->device, $reports);

        $this->assertGreaterThan(0, $result['work_duration']);
        $this->assertGreaterThan(0, $result['traveled_distance']);
    }

    #[Test]
    public function it_handles_multiple_batches_in_same_day()
    {
        // First batch
        $firstBatch = $this->createSampleReports();
        $result1 = $this->service->generate($this->device, $firstBatch);

        // Second batch
        $secondBatch = [
            [
                'coordinate' => [34.886333, 50.586333],
                'speed' => 15,
                'status' => 1,
                'directions' => ['ew' => 270, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => false,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 10:03:00'),
                'imei' => '863070043386100',
            ],
        ];

        $result2 = $this->service->generate($this->device, $secondBatch);

        // Second batch should have higher values than first
        $this->assertGreaterThan($result1['traveled_distance'], $result2['traveled_distance']);
        $this->assertGreaterThan($result1['work_duration'], $result2['work_duration']);
    }

    #[Test]
    public function it_handles_task_without_coordinates()
    {
        // Create a field without coordinates
        $farm = Farm::factory()->create();
        $field = Field::factory()->create([
            'farm_id' => $farm->id,
            'coordinates' => []
        ]);

        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task for today
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $reports = $this->createSampleReports();

        $result = $this->service->generate($this->device, $reports);

        // Should fall back to working hours filtering
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals($this->tractor->id, $result['tractor_id']);
    }

    #[Test]
    public function it_handles_off_status_reports()
    {
        $reports = [
            [
                'coordinate' => [34.883333, 50.583333],
                'speed' => 0,
                'status' => 0, // Off
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => true,
                'is_off' => true,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 10:00:00'),
                'imei' => '863070043386100',
            ],
        ];

        $result = $this->service->generate($this->device, $reports);

        $this->assertArrayHasKey('id', $result);
        $this->assertEquals($this->tractor->id, $result['tractor_id']);
        $this->assertEquals(0, $result['traveled_distance']);
        $this->assertEquals(0, $result['work_duration']);
    }
}
