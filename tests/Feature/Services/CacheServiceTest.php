<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\CacheService;
use App\Models\GpsDevice;
use App\Models\GpsReport;
use App\Models\Tractor;
use App\Models\User;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class CacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevice $device;
    private CacheService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $user = User::factory()->create();
        $tractor = Tractor::factory()->create();

        $this->device = GpsDevice::factory()->create([
            'user_id' => $user->id,
            'tractor_id' => $tractor->id,
            'imei' => '863070043386100'
        ]);

        $this->service = new CacheService($this->device);
    }

    #[Test]
    public function it_stores_and_retrieves_previous_report()
    {
        $report = [
            'coordinate' => [34.883333, 50.583333],
            'speed' => 5,
            'status' => 1,
            'ew_direction' => 0,
            'ns_direction' => 0,
            'is_starting_point' => false,
            'is_ending_point' => false,
            'is_stopped' => false,
            'is_off' => false,
            'stoppage_time' => 0,
            'date_time' => Carbon::parse('2024-01-24 10:00:00'),
            'imei' => '863070043386100',
        ];

        $this->service->setPreviousReport($report);

        $retrievedReport = $this->service->getPreviousReport();

        $this->assertNotNull($retrievedReport);
        $this->assertEquals($report['coordinate'], $retrievedReport['coordinate']);
        $this->assertEquals($report['speed'], $retrievedReport['speed']);
        $this->assertEquals($report['status'], $retrievedReport['status']);
        $this->assertEquals($report['imei'], $retrievedReport['imei']);
    }

    #[Test]
    public function it_returns_null_when_no_previous_report()
    {
        $retrievedReport = $this->service->getPreviousReport();

        $this->assertNull($retrievedReport);
    }

    #[Test]
    public function it_stores_and_retrieves_latest_stored_report()
    {
        $report = GpsReport::factory()->create([
            'gps_device_id' => $this->device->id,
            'imei' => '863070043386100',
            'coordinate' => [34.883333, 50.583333],
            'speed' => 10,
            'status' => 1,
            'is_stopped' => false,
            'is_starting_point' => false,
            'is_ending_point' => false,
            'stoppage_time' => 0,
            'date_time' => Carbon::parse('2024-01-24 10:00:00')
        ]);

        $this->service->setLatestStoredReport($report);

        $retrievedReport = $this->service->getLatestStoredReport();

        $this->assertNotNull($retrievedReport);
        $this->assertInstanceOf(GpsReport::class, $retrievedReport);
        $this->assertEquals($report->id, $retrievedReport->id);
        $this->assertEquals($report->speed, $retrievedReport->speed);
        $this->assertEquals($report->coordinate, $retrievedReport->coordinate);
    }

    #[Test]
    public function it_returns_null_when_no_latest_stored_report()
    {
        $retrievedReport = $this->service->getLatestStoredReport();

        $this->assertNull($retrievedReport);
    }

    #[Test]
    public function it_stores_and_retrieves_validated_state()
    {
        $this->service->setValidatedState('moving');

        $state = $this->service->getValidatedState();

        $this->assertEquals('moving', $state);
    }

    #[Test]
    public function it_returns_default_validated_state()
    {
        $state = $this->service->getValidatedState();

        $this->assertEquals('unknown', $state);
    }

    #[Test]
    public function it_handles_different_validated_states()
    {
        $states = ['moving', 'stopped', 'unknown'];

        foreach ($states as $state) {
            $this->service->setValidatedState($state);
            $retrievedState = $this->service->getValidatedState();
            $this->assertEquals($state, $retrievedState);
        }
    }

    #[Test]
    public function it_manages_pending_reports()
    {
        $report1 = [
            'coordinate' => [34.883333, 50.583333],
            'speed' => 5,
            'status' => 1,
            'ew_direction' => 0,
            'ns_direction' => 0,
            'is_starting_point' => false,
            'is_ending_point' => false,
            'is_stopped' => false,
            'is_off' => false,
            'stoppage_time' => 0,
            'date_time' => Carbon::parse('2024-01-24 10:00:00'),
            'imei' => '863070043386100',
        ];

        $report2 = [
            'coordinate' => [34.884333, 50.584333],
            'speed' => 10,
            'status' => 1,
            'ew_direction' => 90,
            'ns_direction' => 0,
            'is_starting_point' => false,
            'is_ending_point' => false,
            'is_stopped' => false,
            'is_off' => false,
            'stoppage_time' => 0,
            'date_time' => Carbon::parse('2024-01-24 10:01:00'),
            'imei' => '863070043386100',
        ];

        // Initially empty
        $pendingReports = $this->service->getPendingReports();
        $this->assertIsArray($pendingReports);
        $this->assertCount(0, $pendingReports);

        // Add first report
        $this->service->addPendingReport($report1);
        $pendingReports = $this->service->getPendingReports();
        $this->assertCount(1, $pendingReports);
        $this->assertEquals($report1['speed'], $pendingReports[0]['speed']);

        // Add second report
        $this->service->addPendingReport($report2);
        $pendingReports = $this->service->getPendingReports();
        $this->assertCount(2, $pendingReports);
        $this->assertEquals($report2['speed'], $pendingReports[1]['speed']);

        // Clear pending reports
        $this->service->clearPendingReports();
        $pendingReports = $this->service->getPendingReports();
        $this->assertCount(0, $pendingReports);
    }

    #[Test]
    public function it_manages_consecutive_count()
    {
        // Initially zero
        $count = $this->service->getConsecutiveCount();
        $this->assertEquals(0, $count);

        // Set count
        $this->service->setConsecutiveCount(5);
        $count = $this->service->getConsecutiveCount();
        $this->assertEquals(5, $count);

        // Increment count
        $newCount = $this->service->incrementConsecutiveCount();
        $this->assertEquals(6, $newCount);

        $count = $this->service->getConsecutiveCount();
        $this->assertEquals(6, $count);

        // Reset count
        $this->service->resetConsecutiveCount();
        $count = $this->service->getConsecutiveCount();
        $this->assertEquals(0, $count);
    }

    #[Test]
    public function it_handles_multiple_increments()
    {
        $this->service->setConsecutiveCount(0);

        $count = $this->service->incrementConsecutiveCount();
        $this->assertEquals(1, $count);

        $count = $this->service->incrementConsecutiveCount();
        $this->assertEquals(2, $count);

        $count = $this->service->incrementConsecutiveCount();
        $this->assertEquals(3, $count);
    }

    #[Test]
    public function it_uses_device_specific_cache_keys()
    {
        $device2 = GpsDevice::factory()->create([
            'imei' => '863070043386101'
        ]);

        $service2 = new CacheService($device2);

        // Set data for first device
        $this->service->setValidatedState('moving');
        $this->service->setConsecutiveCount(5);

        // Set data for second device
        $service2->setValidatedState('stopped');
        $service2->setConsecutiveCount(10);

        // Verify isolation
        $this->assertEquals('moving', $this->service->getValidatedState());
        $this->assertEquals(5, $this->service->getConsecutiveCount());

        $this->assertEquals('stopped', $service2->getValidatedState());
        $this->assertEquals(10, $service2->getConsecutiveCount());
    }

    #[Test]
    public function it_handles_large_pending_reports_array()
    {
        $reports = [];
        for ($i = 0; $i < 100; $i++) {
            $reports[] = [
                'coordinate' => [34.883333 + ($i * 0.001), 50.583333 + ($i * 0.001)],
                'speed' => $i,
                'status' => 1,
                'ew_direction' => 0,
                'ns_direction' => 0,
                'is_starting_point' => false,
                'is_ending_point' => false,
                'is_stopped' => false,
                'is_off' => false,
                'stoppage_time' => 0,
                'date_time' => Carbon::parse('2024-01-24 10:00:00')->addSeconds($i),
                'imei' => '863070043386100',
            ];
        }

        foreach ($reports as $report) {
            $this->service->addPendingReport($report);
        }

        $pendingReports = $this->service->getPendingReports();
        $this->assertCount(100, $pendingReports);

        // Verify first and last reports
        $this->assertEquals(0, $pendingReports[0]['speed']);
        $this->assertEquals(99, $pendingReports[99]['speed']);
    }

    #[Test]
    public function it_handles_null_and_empty_values()
    {
        // Test with null values
        $this->service->setConsecutiveCount(0);
        $count = $this->service->getConsecutiveCount();
        $this->assertEquals(0, $count); // Should default to 0

        // Test with empty string
        $this->service->setValidatedState('');
        $state = $this->service->getValidatedState();
        $this->assertEquals('', $state);

        // Test with empty array
        $this->service->addPendingReport([]);
        $pendingReports = $this->service->getPendingReports();
        $this->assertCount(1, $pendingReports);
        $this->assertIsArray($pendingReports[0]);
    }

    #[Test]
    public function it_handles_cache_expiration()
    {
        // Set data
        $this->service->setValidatedState('moving');
        $this->service->setConsecutiveCount(5);

        // Verify data exists
        $this->assertEquals('moving', $this->service->getValidatedState());
        $this->assertEquals(5, $this->service->getConsecutiveCount());

        // Simulate cache expiration by manually clearing
        Cache::flush();

        // Data should be gone
        $this->assertEquals('unknown', $this->service->getValidatedState());
        $this->assertEquals(0, $this->service->getConsecutiveCount());
    }

    #[Test]
    public function it_handles_complex_report_data()
    {
        $complexReport = [
            'coordinate' => [34.883333, 50.583333],
            'speed' => 15,
            'status' => 1,
            'ew_direction' => 180,
            'ns_direction' => 1,
            'is_starting_point' => true,
            'is_ending_point' => false,
            'is_stopped' => false,
            'is_off' => false,
            'stoppage_time' => 120,
            'date_time' => Carbon::parse('2024-01-24 10:30:45'),
            'imei' => '863070043386100',
        ];

        $this->service->setPreviousReport($complexReport);

        $retrievedReport = $this->service->getPreviousReport();

        $this->assertNotNull($retrievedReport);
        $this->assertEquals($complexReport['coordinate'], $retrievedReport['coordinate']);
        $this->assertEquals($complexReport['speed'], $retrievedReport['speed']);
        $this->assertEquals($complexReport['ew_direction'], $retrievedReport['ew_direction']);
        $this->assertEquals($complexReport['ns_direction'], $retrievedReport['ns_direction']);
        $this->assertEquals($complexReport['is_starting_point'], $retrievedReport['is_starting_point']);
        $this->assertEquals($complexReport['stoppage_time'], $retrievedReport['stoppage_time']);
        $this->assertInstanceOf(Carbon::class, $retrievedReport['date_time']);
    }
}
