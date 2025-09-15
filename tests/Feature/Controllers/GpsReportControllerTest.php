<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use App\Models\GpsReport;
use App\Models\GpsMetricsCalculation;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

class GpsReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevice $device;
    private Tractor $tractor;

    private function createGpsData(string $coordinates = '3453.00000,05035.0000', string $time = '080000', int $speed = 0, int $status = 1, int $ewDirection = 0, int $nsDirection = 0, string $imei = '863070043386100'): string
    {
        $today = Carbon::now()->format('ymd'); // Use the current test time
        $speedFormatted = sprintf('%03d', $speed); // Format speed as 3 digits
        return "+Hooshnic:V1.03,{$coordinates},000,{$today},{$time},{$speedFormatted},000,{$status},{$ewDirection},{$nsDirection},{$imei}";
    }
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear all GPS reports before each test
        GpsReport::query()->delete();
        GpsMetricsCalculation::query()->delete();

        Cache::flush();
        Event::fake();
        // Carbon::setTestNow('2024-01-24 10:00:00'); // Removed to use real current time

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

    protected function tearDown(): void
    {
        // Clear all cache keys related to this device
        if (isset($this->device)) {
            $deviceId = $this->device->id;
            Cache::forget("latest_stored_report_{$deviceId}");
            Cache::forget("previous_report_{$deviceId}");
            Cache::forget("tractor_working_hours_{$this->tractor->id}_" . now()->toDateString());
            Cache::forget("tractor_start_work_time_{$this->tractor->id}");
        }
        Cache::flush();
        parent::tearDown();
    }

    #[Test]
    public function it_processes_valid_gps_data_successfully()
    {
        $jsonData = json_encode([
            ['data' => $this->createGpsData()],
            ['data' => $this->createGpsData('3453.01000,05035.0100', '080100', 5, 1, 90, 0)]
        ]);

        $response = $this->call('POST', '/api/gps/reports', [], [], [], ['CONTENT_TYPE' => 'application/json'], $jsonData);

        $response->assertStatus(200);
        $response->assertJson([]);

        // Check that reports were created
        $reports = GpsReport::where('imei', '863070043386100')->get();
        $this->assertCount(2, $reports);

        // Check that daily report was created
        $dailyReport = GpsMetricsCalculation::where('tractor_id', $this->tractor->id)
            ->where('date', Carbon::now()->toDateString())
            ->first();
        $this->assertNotNull($dailyReport);
    }

    #[Test]
    public function it_dispatches_report_received_event()
    {
        $jsonData = json_encode([
            ['data' => $this->createGpsData()]
        ]);

        $this->call('POST', '/api/gps/reports', [], [], [], ['CONTENT_TYPE' => 'application/json'], $jsonData);

        Event::assertDispatched(\App\Events\ReportReceived::class, function ($event) {
            return $event->getDevice()->id === $this->device->id;
        });
    }

    #[Test]
    public function it_dispatches_tractor_status_event()
    {
        $jsonData = json_encode([
            ['data' => $this->createGpsData()]
        ]);

        $this->call('POST', '/api/gps/reports', [], [], [], ['CONTENT_TYPE' => 'application/json'], $jsonData);

        Event::assertDispatched(\App\Events\TractorStatus::class, function ($event) {
            return $event->getTractor()->id === $this->tractor->id;
        });
    }

    #[Test]
    public function it_handles_invalid_device_imei()
    {
        $jsonData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,000,000,1,000,0,999999999999999']
        ]);

        $response = $this->call('POST', '/api/gps/reports', [], [], [], ['CONTENT_TYPE' => 'application/json'], $jsonData);

        $response->assertStatus(200); // Still returns 200 but logs error
    }

    #[Test]
    public function it_handles_malformed_json()
    {
        $malformedData = '{"data": "+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,000,000,1,000,0,863070043386100"}{"data": "+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,005,000,1,090,0,863070043386100"}';

        $response = $this->call('POST', '/api/gps/reports', [], [], [], ['CONTENT_TYPE' => 'application/json'], $malformedData);

        $response->assertStatus(200);
    }

    #[Test]
    public function it_handles_invalid_data_format()
    {
        $jsonData = [
            ['data' => 'invalid_format'],
            ['data' => $this->createGpsData()]
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);

        $response->assertStatus(200);

        // Should still process valid data
        $reports = GpsReport::where('imei', '863070043386100')->get();
        $this->assertCount(1, $reports);
    }

    #[Test]
    public function it_handles_multiple_reports_in_single_request()
    {
        $jsonData = [
            ['data' => $this->createGpsData()],
            ['data' => $this->createGpsData('3453.01000,05035.0100', '070100', 5, 1, 90, 0)],
            ['data' => $this->createGpsData('3453.02000,05035.0200', '070200', 10, 1, 180, 0)],
            ['data' => $this->createGpsData('3453.03000,05035.0300', '070300', 15, 1, 270, 0)]
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);

        $response->assertStatus(200);

        $reports = GpsReport::where('imei', '863070043386100')->get();
        // With new stoppage accumulation logic: all reports are saved
        // Report 1: speed=0 (stopped) - saved (first ever)
        // Report 2: speed=5 (moving) - saved (moving report)
        // Report 3: speed=10 (moving) - saved (moving report)
        // Report 4: speed=15 (moving) - saved (moving report)
        // Report 5: might be a detection report if there's a transition
        $this->assertGreaterThanOrEqual(4, $reports->count());
        $this->assertLessThanOrEqual(5, $reports->count());
    }

    #[Test]
    public function it_handles_reports_from_different_days()
    {
        $yesterday = Carbon::yesterday();
        $yesterdayFormatted = $yesterday->format('ymd');

        $jsonData = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $yesterdayFormatted . ',070000,000,000,1,000,0,863070043386100'], // yesterday
            ['data' => $this->createGpsData('3453.01000,05035.0100', '070100', 5, 1, 90, 0)]  // today
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);

        $response->assertStatus(200);

        // Should only process today's reports
        $reports = GpsReport::where('imei', '863070043386100')->get();
        $this->assertCount(1, $reports);
        $this->assertTrue($reports[0]->date_time->isToday());
    }

    #[Test]
    public function it_handles_large_request_body()
    {
        $jsonData = [];
        for ($i = 0; $i < 100; $i++) {
            $time = sprintf('08%04d', $i); // Start from 08:00:00 and increment seconds
            $coordinates = sprintf('3453.%05d,05035.%04d', $i, $i);
            $speed = $i % 2 === 0 ? 5 : 0; // Alternate between moving (5) and stopped (0)
            $jsonData[] = [
                'data' => $this->createGpsData($coordinates, $time, $speed, 1, 0, 0)
            ];
        }

        $response = $this->postJson('/api/gps/reports', $jsonData);

        $response->assertStatus(200);

        $reports = GpsReport::where('imei', '863070043386100')->get();
        // With new stoppage accumulation logic:
        // - All movement reports are saved (50 reports)
        // - First stoppage report after each movement is saved for detection (50 reports)
        // Total: 100 reports
        $this->assertCount(100, $reports);

        // Should have 50 movement reports and 50 stoppage reports
        $movementReports = $reports->where('is_stopped', false);
        $stoppageReports = $reports->where('is_stopped', true);

        $this->assertCount(50, $movementReports);
        $this->assertCount(50, $stoppageReports);
    }


    #[Test]
    public function it_handles_device_without_tractor()
    {
        // Create a device without a tractor
        $deviceWithoutTractor = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => null,
            'imei' => '863070043386101'
        ]);

        $jsonData = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,000,000,1,000,0,863070043386101']
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);

        $response->assertStatus(200); // Should still return 200 but log error
    }
}
