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
                'is_stopped' => true,
                'stoppage_time' => 0,
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
            [
                'coordinate' => [34.884066, 50.599626],
                'speed' => 20,
                'status' => 1,
                'date_time' => $now->copy()->subMinute(),
                'imei' => '863070043386100',
                'is_stopped' => false,
                'stoppage_time' => 0,
                'is_starting_point' => false,
                'is_ending_point' => false,
            ],
            [
                'coordinate' => [34.884067, 50.599627],
                'speed' => 0,
                'status' => 1,
                'date_time' => $now->copy(),
                'imei' => '863070043386100',
                'is_stopped' => true,
                'stoppage_time' => 0,
                'is_starting_point' => false,
                'is_ending_point' => false,
            ]
        ];

        $this->taskService = new TractorTaskService($this->tractor);
        $this->dailyReportService = new DailyReportService($this->tractor, null);
        $this->cacheService = new CacheService($this->device);
        $this->reportProcessingService = new ReportProcessingService(
            $this->device,
            $this->reports,
            null,
            null,
            fn($report) => $this->isWithinWorkingHours($report),
            $this->cacheService
        );

        $this->service = new LiveReportService(
            $this->device,
            $this->reports,
            $this->taskService,
            $this->dailyReportService,
            $this->cacheService,
            $this->reportProcessingService
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
        $report = $this->service->generate();

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
        $this->assertEquals(1, $report['stoppage_count']); // Only stoppages > 60s are counted
        $this->assertCount(3, $report['points']);
    }

    #[Test]
    public function it_calculates_efficiency_correctly()
    {
        $report = $this->service->generate();

        // Moving time is 60 seconds out of 8 hours expected work time
        $expectedEfficiency = (60 / (8 * 3600)) * 100;

        $this->assertEquals(round($expectedEfficiency, 2), round($report['efficiency'], 2));
    }

    #[Test]
    public function it_tracks_stoppage_time()
    {
        $report = $this->service->generate();

        // First and last points are stopped, middle point is moving
        // Total stoppage time should be around 120 seconds
        $this->assertGreaterThan(0, $report['stoppage_duration']);
        $this->assertEquals(1, $report['stoppage_count']);
    }

    #[Test]
    public function it_calculates_traveled_distance()
    {
        $report = $this->service->generate();

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
            'start_work_time' => $startTime,
            'end_work_time' => $endTime,
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
            'start_work_time' => now()->addHours(2),
            'end_work_time' => now()->addHours(10),
        ]);

        $report = $this->service->generate();

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
                'is_stopped' => true,
                'stoppage_time' => 0,
                'is_starting_point' => false,
                'is_ending_point' => false,
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
                'is_stopped' => false,
                'stoppage_time' => 0,
                'is_starting_point' => false,
                'is_ending_point' => false,
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
                'is_stopped' => true,
                'stoppage_time' => 0,
                'is_starting_point' => false,
                'is_ending_point' => false,
            ];
            $currentTime->addSeconds(20);
        }

        // Process each report
        foreach ($reports as $report) {
            $reportProcessingService = new ReportProcessingService(
                $this->device,
                [$report],
                null,
                null,
                fn($report) => $this->isWithinWorkingHours($report),
                $this->cacheService
            );

            $dailyReportService = new DailyReportService($this->device->tractor, null);
            $service = new LiveReportService(
                $this->device,
                [$report],
                $this->taskService,
                $dailyReportService,
                $this->cacheService,
                $reportProcessingService
            );
            $service->generate();
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
}
