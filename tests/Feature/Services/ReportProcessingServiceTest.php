<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\ReportProcessingService;
use App\Services\CacheService;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use App\Models\TractorTask;
use App\Models\Field;
use App\Models\Farm;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class ReportProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevice $device;
    private Tractor $tractor;
    private User $user;
    private ReportProcessingService $service;

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
    public function it_processes_reports_and_calculates_metrics()
    {
        $reports = $this->createSampleReports();
        $service = new ReportProcessingService($this->device, $reports);

        $result = $service->process();

        $this->assertArrayHasKey('totalTraveledDistance', $result);
        $this->assertArrayHasKey('totalMovingTime', $result);
        $this->assertArrayHasKey('totalStoppedTime', $result);
        $this->assertArrayHasKey('stoppageCount', $result);
        $this->assertArrayHasKey('maxSpeed', $result);
        $this->assertArrayHasKey('points', $result);
        $this->assertArrayHasKey('latestStoredReport', $result);

        // First report is stopped, second and third are moving
        // Distance between reports: ~0.14km each (roughly)
        $this->assertGreaterThan(0, $result['totalTraveledDistance']);
        $this->assertEquals(120, $result['totalMovingTime']); // 2 minutes moving
        $this->assertEquals(0, $result['totalStoppedTime']); // No stopped time in this sequence
        $this->assertEquals(10, $result['maxSpeed']);
        $this->assertCount(3, $result['points']); // All reports should be in points
    }

    #[Test]
    public function it_persists_reports_correctly()
    {
        $reports = $this->createSampleReports();
        $service = new ReportProcessingService($this->device, $reports);

        $service->process();

        // Check that reports were persisted
        $persistedReports = $this->device->reports()->get();
        $this->assertCount(3, $persistedReports);

        // First report should be persisted (first ever)
        $firstReport = $persistedReports->first();
        $this->assertTrue($firstReport->is_stopped);
        $this->assertEquals(0, $firstReport->speed);

        // Second and third reports should be persisted (moving reports)
        $secondReport = $persistedReports->skip(1)->first();
        $this->assertFalse($secondReport->is_stopped);
        $this->assertEquals(5, $secondReport->speed);
    }

    #[Test]
    public function it_handles_stopped_to_moving_transitions()
    {
        $reports = [
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
        ];

        $service = new ReportProcessingService($this->device, $reports);
        $result = $service->process();

        // Should count the transition time as moving time
        $this->assertEquals(60, $result['totalMovingTime']);
        $this->assertGreaterThan(0, $result['totalTraveledDistance']);
    }

    #[Test]
    public function it_handles_moving_to_stopped_transitions()
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
                'date_time' => Carbon::parse('2024-01-24 10:00:00'),
                'imei' => '863070043386100',
            ],
            [
                'coordinate' => [34.884333, 50.584333],
                'speed' => 0,
                'status' => 1,
                'directions' => ['ew' => 90, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => true,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 10:01:00'),
                'imei' => '863070043386100',
            ],
        ];

        $service = new ReportProcessingService($this->device, $reports);
        $result = $service->process();

        // Should count the transition time as moving time and add distance
        $this->assertEquals(60, $result['totalMovingTime']);
        $this->assertGreaterThan(0, $result['totalTraveledDistance']);
        $this->assertEquals(1, $result['stoppageCount']); // One stopped report persisted
    }

    #[Test]
    public function it_handles_stopped_to_stopped_transitions()
    {
        $reports = [
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
                'coordinate' => [34.883333, 50.583333], // Same location
                'speed' => 0,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => true,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 10:01:00'),
                'imei' => '863070043386100',
            ],
        ];

        $service = new ReportProcessingService($this->device, $reports);
        $result = $service->process();

        // Should count the time as stopped time
        $this->assertEquals(60, $result['totalStoppedTime']);
        $this->assertEquals(0, $result['totalMovingTime']);
        $this->assertEquals(0, $result['totalTraveledDistance']);
        $this->assertEquals(1, $result['stoppageCount']); // Only first stopped report persisted
    }

    #[Test]
    public function it_handles_moving_to_moving_transitions()
    {
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
                'date_time' => Carbon::parse('2024-01-24 10:00:00'),
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
                'date_time' => Carbon::parse('2024-01-24 10:01:00'),
                'imei' => '863070043386100',
            ],
        ];

        $service = new ReportProcessingService($this->device, $reports);
        $result = $service->process();

        // Should count the time as moving time
        $this->assertEquals(60, $result['totalMovingTime']);
        $this->assertGreaterThan(0, $result['totalTraveledDistance']);
        $this->assertEquals(10, $result['maxSpeed']);
    }

    #[Test]
    public function it_respects_working_hours_filtering()
    {
        // Set working hours to 12:00-14:00
        $this->tractor->update([
            'start_work_time' => '12:00',
            'end_work_time' => '14:00'
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
                'date_time' => Carbon::parse('2024-01-24 10:00:00'), // Before working hours
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
                'date_time' => Carbon::parse('2024-01-24 13:00:00'), // During working hours
                'imei' => '863070043386100',
            ],
        ];

        $service = new ReportProcessingService($this->device, $reports);
        $result = $service->process();

        // Should not count metrics for reports outside working hours
        $this->assertEquals(0, $result['totalMovingTime']);
        $this->assertEquals(0, $result['totalTraveledDistance']);
    }

    #[Test]
    public function it_respects_task_area_filtering()
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

        // Create a task for today
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $reports = [
            [
                'coordinate' => [34.875, 50.575], // Outside task area
                'speed' => 5,
                'status' => 1,
                'directions' => ['ew' => 0, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => false,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 10:00:00'),
                'imei' => '863070043386100',
            ],
            [
                'coordinate' => [34.885, 50.585], // Inside task area
                'speed' => 10,
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
        ];

        $service = new ReportProcessingService($this->device, $reports, $task, $field->coordinates);
        $result = $service->process();

        // Should not count metrics for reports outside task area
        $this->assertEquals(0, $result['totalMovingTime']);
        $this->assertEquals(0, $result['totalTraveledDistance']);
    }

    #[Test]
    public function it_handles_out_of_order_reports()
    {
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
                'date_time' => Carbon::parse('2024-01-24 10:01:00'),
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
                'date_time' => Carbon::parse('2024-01-24 10:00:00'), // Earlier timestamp
                'imei' => '863070043386100',
            ],
        ];

        $service = new ReportProcessingService($this->device, $reports);
        $result = $service->process();

        // Should ignore out-of-order reports
        $this->assertEquals(0, $result['totalMovingTime']);
        $this->assertEquals(0, $result['totalTraveledDistance']);
    }

    #[Test]
    public function it_tracks_max_speed_correctly()
    {
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
                'date_time' => Carbon::parse('2024-01-24 10:00:00'),
                'imei' => '863070043386100',
            ],
            [
                'coordinate' => [34.884333, 50.584333],
                'speed' => 25,
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
                'speed' => 15,
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

        $service = new ReportProcessingService($this->device, $reports);
        $result = $service->process();

        $this->assertEquals(25, $result['maxSpeed']);
    }

    #[Test]
    public function it_handles_multiple_stoppage_segments()
    {
        $reports = [
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
                'speed' => 0,
                'status' => 1,
                'directions' => ['ew' => 180, 'ns' => 0],
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => true,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 10:02:00'),
                'imei' => '863070043386100',
            ],
        ];

        $service = new ReportProcessingService($this->device, $reports);
        $result = $service->process();

        // Should have 2 stoppage counts (first and third reports)
        $this->assertEquals(2, $result['stoppageCount']);
    }

    #[Test]
    public function it_uses_cache_for_previous_report()
    {
        $cacheService = new CacheService($this->device);

        // Set a previous report in cache
        $previousReport = [
            'coordinate' => [34.883333, 50.583333],
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

        $reports = [
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
                'date_time' => Carbon::parse('2024-01-24 10:00:00'),
                'imei' => '863070043386100',
            ],
        ];

        $service = new ReportProcessingService($this->device, $reports);
        $result = $service->process();

        // Should calculate metrics using cached previous report
        $this->assertEquals(60, $result['totalMovingTime']);
        $this->assertGreaterThan(0, $result['totalTraveledDistance']);
    }
}
