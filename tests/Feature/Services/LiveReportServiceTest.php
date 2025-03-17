<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\LiveReportService;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\GpsDailyReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LiveReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevice $device;
    private array $reports;
    private LiveReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2024-01-24 07:02:00');

        $tractor = Tractor::factory()->create([
            'start_work_time' => now()->subHours(2),
            'end_work_time' => now()->addHours(8),
            'expected_daily_work_time' => 8 // 8 hours
        ]);

        $this->device = GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070043386100'
        ]);

        GpsDailyReport::create([
            'tractor_id' => $tractor->id,
            'date' => today(),
            'max_speed' => 0
        ]);

        $now = now();
        $this->reports = [
            [
                'latitude' => 34.884065,
                'longitude' => 50.599625,
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
                'latitude' => 34.884066,
                'longitude' => 50.599626,
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
                'latitude' => 34.884067,
                'longitude' => 50.599627,
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

        $this->service = new LiveReportService($this->device, $this->reports, cache()->store());
    }

    /** @test */
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
        $this->assertEquals(2, $report['stoppage_count']); // Two points with speed 0
        $this->assertCount(3, $report['points']);
    }

    /** @test */
    public function it_calculates_efficiency_correctly()
    {
        $report = $this->service->generate();

        // Moving time is 60 seconds out of 8 hours expected work time
        $expectedEfficiency = (60 / (8 * 3600)) * 100;

        $this->assertEquals(round($expectedEfficiency, 2), round($report['efficiency'], 2));
    }

    /** @test */
    public function it_tracks_stoppage_time()
    {
        $report = $this->service->generate();

        // First and last points are stopped, middle point is moving
        // Total stoppage time should be around 120 seconds
        $this->assertGreaterThan(0, $report['stoppage_duration']);
        $this->assertEquals(2, $report['stoppage_count']);
    }

    /** @test */
    public function it_calculates_traveled_distance()
    {
        $report = $this->service->generate();

        // Distance between consecutive points should be calculated
        // using the Haversine formula
        $this->assertGreaterThan(0, $report['traveled_distance']);
    }

    /**
     * Test the live report service calculates metrics only if the tractor is in task field
     */
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
}
