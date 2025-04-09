<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use App\Events\ReportReceived;
use App\Events\TractorStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class GpsReportTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevice $device;
    private User $user;
    private Tractor $tractor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2024-01-24 07:02:00');

        $this->user = User::factory()->create();
        $this->tractor = Tractor::factory()->create([
            'start_work_time' => now()->subHours(2),
            'end_work_time' => now()->addHours(8),
        ]);

        $this->device = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => $this->tractor->id,
            'imei' => '863070043386100'
        ]);
    }

    #[Test]
    public function it_processes_gps_report_and_broadcasts_events()
    {
        Event::fake([ReportReceived::class, TractorStatus::class]);

        $jsonData = [
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070200,018,000,1,863070043386100']
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);

        $response->assertStatus(200);

        // Assert report was stored in database
        $this->assertDatabaseHas('gps_reports', [
            'gps_device_id' => $this->device->id,
            'imei' => '863070043386100',
            'speed' => 18,
            'status' => 1,
            'is_stopped' => 0
        ]);

        // Get the report and check coordinate structure
        $report = \App\Models\GpsReport::where('imei', '863070043386100')->first();
        $this->assertIsArray($report->coordinate);
        $this->assertCount(2, $report->coordinate);
        $this->assertEquals(34.884065, $report->coordinate[0]);
        $this->assertEquals(50.599625, $report->coordinate[1]);

        Event::assertDispatched(ReportReceived::class);
        Event::assertDispatched(TractorStatus::class);
    }

    #[Test]
    public function it_calculates_travel_metrics_for_multiple_reports()
    {
        $jsonData = [
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070000,000,000,1,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070100,020,000,1,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04394,05035.9776,000,240124,070200,000,000,1,863070043386100']
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);

        $response->assertStatus(200);

        $dailyReport = $this->tractor->gpsDailyReports()->first();

        // Assert metrics were calculated
        $this->assertGreaterThan(0, $dailyReport->traveled_distance);
        $this->assertGreaterThan(0, $dailyReport->work_duration);
        $this->assertGreaterThan(0, $dailyReport->stoppage_count);
        $this->assertEquals(20, $dailyReport->max_speed);
    }

    #[Test]
    public function it_handles_invalid_device_imei()
    {
        $jsonData = [
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070200,018,000,1,999999999999999']
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);

        $response->assertStatus(404)
            ->assertJson(['message' => 'Device not found']);

        $this->assertDatabaseMissing('gps_reports', [
            'imei' => '999999999999999'
        ]);
    }

    #[Test]
    public function it_updates_existing_daily_report()
    {
        // First report
        $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070000,020,000,1,863070043386100']
        ]);

        $initialReport = $this->tractor->gpsDailyReports()->first();
        $initialDistance = $initialReport->traveled_distance;

        // Second report
        $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.04394,05035.9776,000,240124,070100,020,000,1,863070043386100']
        ]);

        $updatedReport = $this->tractor->gpsDailyReports()->first()->fresh();

        $this->assertGreaterThan($initialDistance, $updatedReport->traveled_distance);
    }

    #[Test]
    public function it_only_counts_reports_within_working_hours_when_no_task()
    {
        // Set working hours
        $this->tractor->update([
            'start_work_time' => '07:00',
            'end_work_time' => '17:00'
        ]);

        // Send report outside working hours
        $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,060000,020,000,1,863070043386100']
        ]);

        $dailyReport = $this->tractor->gpsDailyReports()->first();
        $this->assertEquals(0, $dailyReport->traveled_distance);
        $this->assertEquals(0, $dailyReport->work_duration);

        // Send report within working hours
        $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.04394,05035.9776,000,240124,080000,020,000,1,863070043386100']
        ]);

        $dailyReport = $this->tractor->gpsDailyReports()->first()->fresh();
        $this->assertGreaterThan(0, $dailyReport->traveled_distance);
        $this->assertGreaterThan(0, $dailyReport->work_duration);
    }
}
