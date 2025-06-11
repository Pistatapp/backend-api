<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\LiveReportService;
use App\Services\DailyReportService;
use App\Services\CacheService;
use App\Services\TractorTaskService;
use App\Services\ReportProcessingService;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\GpsDailyReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class LiveReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevice $device;
    private array $reports;
    private LiveReportService $service;
    private TractorTaskService $taskService;
    private DailyReportService $dailyReportService;
    private CacheService $cacheService;
    private ReportProcessingService $reportProcessingService;
    private User $user;
    private Tractor $tractor;

    protected function setUp(): void
    {
        parent::setUp();

        // Set test time accounting for ParseDataService's timezone adjustment
        Carbon::setTestNow('2024-01-24 10:32:00'); // 07:02 + 3:30 timezone offset

        $this->user = User::factory()->create();
        $this->tractor = Tractor::factory()->create([
            'start_work_time' => '06:00',
            'end_work_time' => '18:00',
            'expected_daily_work_time' => 8 // 8 hours
        ]);

        $this->device = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => $this->tractor->id,
            'imei' => '863070043386100'
        ]);

        GpsDailyReport::create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'max_speed' => 0
        ]);

        $now = now();
        $this->reports = [
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(2),
                'imei' => '863070043386100',
            ],
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 20,
                'status' => 1,
                'date_time' => $now->copy()->subMinute(),
                'imei' => '863070043386100',
            ],
            [
                'coordinate' => [34.884067, 50.599627],
                'speed' => 0,
                'status' => 1,
                'date_time' => $now->copy(),
                'imei' => '863070043386100',
            ]
        ];

        $this->dailyReportService = new DailyReportService($this->tractor, null);
        $this->cacheService = new CacheService($this->device);
        $this->taskService = new TractorTaskService($this->tractor);
        $this->reportProcessingService = new ReportProcessingService(
            $this->device,
            $this->reports,
        );

        $this->service = new LiveReportService(
            $this->taskService,
            $this->dailyReportService,
            $this->cacheService
        );
    }

    private function isWithinWorkingHours($report): bool
    {
        $reportTime = $report['date_time'];
        $startTime = $this->device->tractor->start_work_time;
        $endTime = $this->device->tractor->end_work_time;

        return $reportTime->between($startTime, $endTime);
    }

    #[Test]
    public function it_calculates_live_report_metrics()
    {
        $report = $this->service->generate($this->device, $this->reports);

        $this->assertArrayHasKey('id', $report);
        $this->assertArrayHasKey('tractor_id', $report);
        $this->assertArrayHasKey('traveled_distance', $report);
        $this->assertArrayHasKey('work_duration', $report);
        $this->assertArrayHasKey('stoppage_duration', $report);
        $this->assertArrayHasKey('efficiency', $report);
        $this->assertArrayHasKey('stoppage_count', $report);
        $this->assertArrayHasKey('speed', $report);
        $this->assertArrayHasKey('points', $report);

        // Verify basic calculations
        $this->assertGreaterThan(0, $report['traveled_distance']);
        $this->assertEquals(0, $report['speed']); // Last non-zero speed
        $this->assertEquals(2, $report['stoppage_count']); // Only stoppages > 60s are counted
        $this->assertCount(3, $report['points']);
    }

    #[Test]
    public function it_calculates_efficiency_correctly()
    {
        $report = $this->service->generate($this->device, $this->reports);

        // Moving time is 60 seconds out of 8 hours expected work time
        $expectedEfficiency = (60 / (8 * 3600)) * 100;

        $this->assertEquals(round($expectedEfficiency, 2), round($report['efficiency'], 2));
    }

    #[Test]
    public function it_tracks_stoppage_time()
    {
        $report = $this->service->generate($this->device, $this->reports);

        // First and last points are stopped, middle point is moving
        // Total stoppage time should be around 120 seconds
        $this->assertEquals(60, $report['stoppage_duration']);
        $this->assertEquals(2, $report['stoppage_count']);
    }

    #[Test]
    public function it_calculates_traveled_distance()
    {
        $report = $this->service->generate($this->device, $this->reports);

        // Distance between consecutive points should be calculated
        // using the Haversine formula
        $this->assertGreaterThan(0, $report['traveled_distance']);
    }

    #[Test]
    public function it_correctly_sets_tractor_working_times()
    {
        $startTime = now()->subHours(2);
        $endTime = now()->addHours(8);

        $this->device->tractor->update([
            'start_work_time' => $startTime->format('H:i'),
            'end_work_time' => $endTime->format('H:i'),
        ]);

        $tractor = $this->device->tractor->fresh();

        $this->assertTrue($startTime->equalTo($tractor->start_work_time));
        $this->assertTrue($endTime->equalTo($tractor->end_work_time));

        // Verify that the working hours calculation is correct
        $expectedWorkHours = $startTime->diffInHours($endTime, false);
        $this->assertEquals(10, $expectedWorkHours);
    }

    #[Test]
    public function it_does_not_calculate_metrics_if_tractor_is_not_in_task_field()
    {
        $this->device->tractor->update([
            'start_work_time' => now()->addHours(2)->format('H:i'),
            'end_work_time' => now()->addHours(10)->format('H:i'),
        ]);

        $report = $this->service->generate($this->device, $this->reports);

        $this->assertEquals(0, $report['traveled_distance']);
        $this->assertEquals(0, $report['work_duration']);
        $this->assertEquals(0, $report['stoppage_duration']);
        $this->assertEquals(0, $report['efficiency']);
        $this->assertEquals(0, $report['stoppage_count']);
        $this->assertEquals(0, $report['speed']);
        $this->assertCount(3, $report['points']);
    }

    #[Test]
    public function it_detects_actual_working_times_from_gps_reports()
    {
        // Set theoretical working hours
        $startWorkTime = now()->setHour(8)->setMinute(0);
        $endWorkTime = now()->setHour(17)->setMinute(0);

        $this->device->tractor->update([
            'start_work_time' => $startWorkTime->format('H:i'),
            'end_work_time' => $endWorkTime->format('H:i'),
        ]);

        // Simulate continuous movement reports with 20-second intervals
        $reports = [];
        $currentTime = $startWorkTime->copy()->addMinutes(5);

        // Generate stopped reports (8:05-8:09)
        while ($currentTime->format('H:i') < '08:10') {
            $reports[] = [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 1,
                'date_time' => $currentTime->copy(),
                'imei' => '863070043386100',
            ];
            $currentTime->addSeconds(20);
        }

        // Generate moving reports (8:10-8:20)
        $lat = 34.884065;
        $lng = 50.599625;
        while ($currentTime->format('H:i') < '08:20') {
            $lat += 0.00001;
            $lng += 0.00001;
            $reports[] = [
                'coordinate' => [$lat, $lng],
                'speed' => 15,
                'status' => 1,
                'date_time' => $currentTime->copy(),
                'imei' => '863070043386100',
            ];
            $currentTime->addSeconds(20);
        }

        // Fast forward to end of day

        // Set time to 16:40 for final reports
        $currentTime = $endWorkTime->copy()->subHours(1)->addMinutes(40);

        // Generate final stopped reports (16:40-16:50)
        while ($currentTime->format('H:i') < '16:50') {
            $reports[] = [
                'coordinate' => [34.885065, 50.600625],
                'speed' => 0,
                'status' => 1,
                'date_time' => $currentTime->copy(),
                'imei' => '863070043386100',
            ];
            $currentTime->addSeconds(20);
        }

        // Process each report
        foreach ($reports as $report) {
            $service = new LiveReportService(
                $this->taskService,
                $this->dailyReportService,
                $this->cacheService
            );
            $service->generate($this->device, [$report]);
        }

        // Get the processed reports
        $processedReports = $this->device->reports()
            ->orderBy('date_time')
            ->get();

        // Verify actual start time was detected (when movement began)
        $actualStartReport = $processedReports->where('is_starting_point', true)->first();
        $this->assertNotNull($actualStartReport, 'No start point was detected');
        $this->assertEquals('08:10', $actualStartReport->date_time->format('H:i'));

        // Verify actual end time was detected (when movement stopped)
        $actualEndReport = $processedReports->where('is_ending_point', true)->first();
        $this->assertNotNull($actualEndReport, 'No end point was detected');
        $this->assertEquals('16:40', $actualEndReport->date_time->format('H:i'));
    }

    /**
     * Test: Only the first stoppage after movement is saved, repeated stoppages only increment stoppage_time.
     * Also, repetitive stoppage reports should not be added to the points array.
     */
    #[Test]
    public function it_saves_only_first_stoppage_and_increments_stoppage_time_for_repeated_stoppages()
    {
        $now = now();
        $reports = [
            // First report: movement
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 10,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(3),
                'imei' => $this->device->imei,
            ],
            // First stoppage (should be saved)
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(2),
                'imei' => $this->device->imei,
            ],
            // Second stoppage (should NOT be saved, only stoppage_time incremented)
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinute(),
                'imei' => $this->device->imei,
            ],
            // Third stoppage (should NOT be saved, only stoppage_time incremented)
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now,
                'imei' => $this->device->imei,
            ],
        ];

        $service = new LiveReportService(
            $this->taskService,
            $this->dailyReportService,
            $this->cacheService
        );

        $liveReport = $service->generate($this->device, $reports);

        $savedReports = $this->device->reports()->get();
        $this->assertCount(2, $savedReports, 'Only movement and first stoppage should be saved');
        $this->assertTrue($savedReports[1]->is_stopped, 'Second report should be stoppage');
        $this->assertGreaterThan(0, $savedReports[1]->stoppage_time, 'Stoppage time should be incremented for repeated stoppages');

        // Check that points array doesn't contain repetitive stoppage reports
        $stoppagePoints = array_filter($liveReport['points'], fn($point) => $point['is_stopped']);
        $this->assertCount(1, $stoppagePoints, 'Should only have one stoppage point in the points array');
    }

    /**
     * Test: Movements are always saved after stoppage.
     */
    #[Test]
    public function it_saves_movements_after_stoppage()
    {
        $now = now();
        $reports = [
            // First: stoppage
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(2),
                'imei' => $this->device->imei,
            ],
            // Movement 1
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 15,
                'status' => 1,
                'date_time' => $now->copy()->subMinute(),
                'imei' => $this->device->imei,
            ],
            // Movement 2
            [
                'coordinate' => [34.884067, 50.599627],
                'speed' => 18,
                'status' => 1,
                'date_time' => $now,
                'imei' => $this->device->imei,
            ],
        ];

        $service = new LiveReportService(
            $this->taskService,
            $this->dailyReportService,
            $this->cacheService
        );

        $service->generate($this->device, $reports);

        $savedReports = $this->device->reports()->get();
        $this->assertCount(3, $savedReports, 'All movement and first stoppage should be saved');
        $this->assertFalse($savedReports[1]->is_stopped, 'Second report should be movement');
        $this->assertFalse($savedReports[2]->is_stopped, 'Third report should be movement');
    }

    /**
     * Test: Only the first stoppage after movement is saved, repeated stoppages are not.
     */
    #[Test]
    public function it_saves_only_first_stoppage_after_movement()
    {
        $now = now();
        $reports = [
            // First: movement
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 10,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(3),
                'imei' => $this->device->imei,
            ],
            // Stoppage 1 (should be saved)
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(2),
                'imei' => $this->device->imei,
            ],
            // Stoppage 2 (should NOT be saved)
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinute(),
                'imei' => $this->device->imei,
            ],
        ];

        $service = new LiveReportService(
            $this->taskService,
            $this->dailyReportService,
            $this->cacheService
        );

        $service->generate($this->device, $reports);

        $savedReports = $this->device->reports()->get();
        $this->assertCount(2, $savedReports, 'Only movement and first stoppage should be saved');
        $this->assertTrue($savedReports[1]->is_stopped, 'Second report should be stoppage');
    }

    /**
     * Test: Short stoppages are now counted since MINIMUM_STOPPAGE_DURATION constraint was removed.
     */
    #[Test]
    public function it_counts_all_stoppages_regardless_of_duration()
    {
        $now = now();
        $reports = [
            // First: movement
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 10,
                'status' => 1,
                'date_time' => $now->copy()->subSeconds(70),
                'imei' => $this->device->imei,
            ],
            // Stoppage (any duration)
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subSeconds(30),
                'imei' => $this->device->imei,
            ],
            // Movement again
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 10,
                'status' => 1,
                'date_time' => $now,
                'imei' => $this->device->imei,
            ],
        ];

        $this->service->generate($this->device, $reports);

        $savedReports = $this->device->reports()->get();
        $this->assertCount(3, $savedReports, 'All transitions should be saved');
        $dailyReport = $this->tractor->gpsDailyReports()->where('date', today())->first();
        $this->assertEquals(1, $dailyReport->stoppage_count, 'Stoppage should be counted regardless of duration');
    }

    /**
     * Test: GPS device error - single movement report between stoppage reports should be ignored.
     * According to algorithm: "We should implement a mechanism to detect and make sure the tractor is actually moving
     * then proceed with our movement distance and movement duration calculation process. We may set a condition in our
     * algorithm to check if we get 3 continues movement report, we consider the 4 report an actual movement."
     */
    #[Test]
    public function it_ignores_single_movement_report_between_stoppages_as_gps_error()
    {
        $now = now();
        $reports = [
            // Stoppage 1
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(5),
                'imei' => $this->device->imei,
            ],
            // Stoppage 2
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(4),
                'imei' => $this->device->imei,
            ],
            // Single movement report (GPS error)
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 15,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(3),
                'imei' => $this->device->imei,
            ],
            // Stoppage 3 - back to stopping
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(2),
                'imei' => $this->device->imei,
            ],
            // Stoppage 4
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinute(),
                'imei' => $this->device->imei,
            ],
        ];

        $service = new LiveReportService(
            $this->taskService,
            $this->dailyReportService,
            $this->cacheService
        );

        $service->generate($this->device, $reports);

        $savedReports = $this->device->reports()->get();

        // Should only save first stoppage report and not process the single movement as error
        $this->assertCount(1, $savedReports, 'Only first stoppage should be saved, movement error should be ignored');
        $this->assertTrue($savedReports[0]->is_stopped, 'First report should be stoppage');

        // Daily report should show no movement
        $dailyReport = $this->tractor->gpsDailyReports()->where('date', today())->first();
        $this->assertEquals(0, $dailyReport->traveled_distance, 'No distance should be traveled due to GPS error');
        $this->assertEquals(0, $dailyReport->work_duration, 'No work duration should be recorded due to GPS error');
    }

    /**
     * Test: GPS device error - single stoppage report between movement reports should be ignored.
     * According to algorithm: "The opposite might be possible, sometimes devices is sending movement reports,
     * suddenly sends an stoppage report then keeps sending movement reports. This stoppage is not a real one,
     * we should implement a mechanism to check if we get at least 3 stoppage reports, it is considered an actual stoppage."
     */
    #[Test]
    public function it_ignores_single_stoppage_report_between_movements_as_gps_error()
    {
        $now = now();
        $reports = [
            // Movement 1
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 15,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(5),
                'imei' => $this->device->imei,
            ],
            // Movement 2
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 18,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(4),
                'imei' => $this->device->imei,
            ],
            // Single stoppage report (GPS error)
            [
                'coordinate' => [34.884067, 50.599627],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(3),
                'imei' => $this->device->imei,
            ],
            // Movement 3 - back to moving
            [
                'coordinate' => [34.884068, 50.599628],
                'speed' => 20,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(2),
                'imei' => $this->device->imei,
            ],
            // Movement 4
            [
                'coordinate' => [34.884069, 50.599629],
                'speed' => 22,
                'status' => 1,
                'date_time' => $now->copy()->subMinute(),
                'imei' => $this->device->imei,
            ],
        ];

        $service = new LiveReportService(
            $this->taskService,
            $this->dailyReportService,
            $this->cacheService
        );

        $service->generate($this->device, $reports);

        $savedReports = $this->device->reports()->get();

        // All movement reports should be saved, single stoppage should be ignored
        $this->assertCount(4, $savedReports, 'All movement reports should be saved, stoppage error ignored');
        $this->assertFalse($savedReports[0]->is_stopped, 'First report should be movement');
        $this->assertFalse($savedReports[1]->is_stopped, 'Second report should be movement');
        $this->assertFalse($savedReports[2]->is_stopped, 'Third report should be movement');
        $this->assertFalse($savedReports[3]->is_stopped, 'Fourth report should be movement');

        // Daily report should show continuous movement
        $dailyReport = $this->tractor->gpsDailyReports()->where('date', today())->first();
        $this->assertGreaterThan(0, $dailyReport->traveled_distance, 'Distance should be traveled');
        $this->assertGreaterThan(0, $dailyReport->work_duration, 'Work duration should be recorded');
        $this->assertEquals(0, $dailyReport->stoppage_count, 'No stoppage should be counted due to GPS error');
    }

    /**
     * Test: Valid state change - 3 consecutive movement reports after stoppage should be processed.
     * This represents actual movement after the tractor was stopped.
     */
    #[Test]
    public function it_processes_valid_movement_after_consecutive_movements()
    {
        $now = now();
        $reports = [
            // Initial stoppage
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(6),
                'imei' => $this->device->imei,
            ],
            // Movement 1 (pending)
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 15,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(5),
                'imei' => $this->device->imei,
            ],
            // Movement 2 (pending)
            [
                'coordinate' => [34.884067, 50.599627],
                'speed' => 18,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(4),
                'imei' => $this->device->imei,
            ],
            // Movement 3 (confirms state change)
            [
                'coordinate' => [34.884068, 50.599628],
                'speed' => 20,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(3),
                'imei' => $this->device->imei,
            ],
            // Movement 4 (normal processing)
            [
                'coordinate' => [34.884069, 50.599629],
                'speed' => 22,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(2),
                'imei' => $this->device->imei,
            ],
        ];

        $service = new LiveReportService(
            $this->taskService,
            $this->dailyReportService,
            $this->cacheService
        );

        $service->generate($this->device, $reports);

        $savedReports = $this->device->reports()->get();

        // Should save: stoppage + 4 movement reports
        $this->assertCount(5, $savedReports, 'Should save stoppage and all movement reports after validation');
        $this->assertTrue($savedReports[0]->is_stopped, 'First report should be stoppage');
        $this->assertFalse($savedReports[1]->is_stopped, 'Second report should be movement');
        $this->assertFalse($savedReports[2]->is_stopped, 'Third report should be movement');
        $this->assertFalse($savedReports[3]->is_stopped, 'Fourth report should be movement');
        $this->assertFalse($savedReports[4]->is_stopped, 'Fifth report should be movement');

        // Daily report should show movement metrics
        $dailyReport = $this->tractor->gpsDailyReports()->where('date', today())->first();
        $this->assertGreaterThan(0, $dailyReport->traveled_distance, 'Distance should be traveled');
        $this->assertGreaterThan(0, $dailyReport->work_duration, 'Work duration should be recorded');
    }

    /**
     * Test: Valid state change - 3 consecutive stoppage reports after movement should be processed.
     * This represents actual stoppage after the tractor was moving.
     * Only the first stoppage report should be counted, and repetitive stoppages should not be added to points.
     */
    #[Test]
    public function it_processes_valid_stoppage_after_consecutive_stoppages()
    {
        // Clear cache to ensure clean state
        \Illuminate\Support\Facades\Cache::flush();

        $now = now();
        $reports = [
            // Initial movement
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 20,
                'status' => 1,
                'date_time' => $now->copy()->subMinutes(8),
                'imei' => $this->device->imei,
            ],
            // Stoppage 1 (pending) - 2 minutes after movement
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(6),
                'imei' => $this->device->imei,
            ],
            // Stoppage 2 (pending)
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(5),
                'imei' => $this->device->imei,
            ],
            // Stoppage 3 (confirms state change)
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(4),
                'imei' => $this->device->imei,
            ],
            // Stoppage 4 (normal processing - should not create new record or add to points)
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 0,
                'status' => 0,
                'date_time' => $now->copy()->subMinutes(3),
                'imei' => $this->device->imei,
            ],
        ];

        $service = new LiveReportService(
            $this->taskService,
            $this->dailyReportService,
            $this->cacheService
        );

        $liveReport = $service->generate($this->device, $reports);

        $savedReports = $this->device->reports()->get();

        // Should save: movement + first stoppage (subsequent stoppages just increment time)
        $this->assertCount(2, $savedReports, 'Should save movement and first stoppage only');
        $this->assertFalse($savedReports[0]->is_stopped, 'First report should be movement');
        $this->assertTrue($savedReports[1]->is_stopped, 'Second report should be stoppage');

        // Daily report should show stoppage metrics
        $dailyReport = $this->tractor->gpsDailyReports()->where('date', today())->first();

        $this->assertEquals(1, $dailyReport->stoppage_count, 'Should count one stoppage');
        $this->assertGreaterThan(0, $dailyReport->stoppage_duration, 'Should have stoppage duration');

        // Check that points array doesn't contain repetitive stoppage reports
        $stoppagePoints = array_filter($liveReport['points'], fn($point) => $point['is_stopped']);
        $this->assertCount(1, $stoppagePoints, 'Should only have one stoppage point in the points array');
    }
}
