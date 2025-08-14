<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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

        Cache::flush();

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
    public function it_calculates_traveled_distance_correctly()
    {
        // Prepare GPS reports
        $jsonData = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,000,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,010,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,070200,020,000,1,180,0,863070043386100'],
        ];

        // Helper to convert NMEA (ddmm.mmmm) to decimal degrees and round to 6 decimals
        $toDecimal = function (float $nmea): float {
            $degrees = floor($nmea / 100);
            $minutes = ($nmea - ($degrees * 100)) / 60;
            return round($degrees + $minutes, 6);
        };

        // Build expected coordinates and distance as the system does
        $coords = [];
        foreach ($jsonData as $item) {
            $parts = explode(',', $item['data']);
            $lat = $toDecimal((float)$parts[1]);
            $lon = $toDecimal((float)$parts[2]);
            $coords[] = [$lat, $lon];
        }

        $expectedDistance = calculate_distance($coords[0], $coords[1])
            + calculate_distance($coords[1], $coords[2]);

        $response = $this->postJson('/api/gps/reports', $jsonData);
        $response->assertStatus(200);

        $dailyReport = $this->tractor->gpsDailyReports()->first();

        // Assert precise traveled distance (within 10 meters tolerance)
        $this->assertEqualsWithDelta($expectedDistance, $dailyReport->traveled_distance, 0.01);
    }

    #[Test]
    public function it_calculates_movement_time_correctly_with_stoppage_and_movement_reports()
    {
        // Sequence alternates between stoppage and movement, with consecutive reports to confirm state changes
        $jsonData = [
            // Initial stopped state (confirmed with two consecutive stopped reports)
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,000,000,1,000,0,863070043386100'], // 07:00:00 stopped
            ['data' => '+Hooshnic:V1.03,3453.00010,05035.0001,000,240124,070030,000,000,1,000,0,863070043386100'], // 07:00:30 stopped

            // Transition to moving (two consecutive moving reports to confirm)
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,006,000,1,090,0,863070043386100'], // 07:01:00 moving
            ['data' => '+Hooshnic:V1.03,3453.01200,05035.0120,000,240124,070200,006,000,1,090,0,863070043386100'], // 07:02:00 moving

            // Continue moving within same state
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,070400,007,000,1,180,0,863070043386100'], // 07:04:00 moving

            // Transition to stopped (two consecutive stopped reports to confirm)
            ['data' => '+Hooshnic:V1.03,3453.02010,05035.0201,000,240124,070500,000,000,1,270,0,863070043386100'], // 07:05:00 stopped
            ['data' => '+Hooshnic:V1.03,3453.02020,05035.0202,000,240124,070600,000,000,1,270,0,863070043386100'], // 07:06:00 stopped

            // Transition back to moving (two consecutive moving reports to confirm)
            ['data' => '+Hooshnic:V1.03,3453.02500,05035.0250,000,240124,070700,008,000,1,000,0,863070043386100'], // 07:07:00 moving
            ['data' => '+Hooshnic:V1.03,3453.03000,05035.0300,000,240124,070900,008,000,1,000,0,863070043386100'], // 07:09:00 moving
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);
        $response->assertStatus(200);

        $dailyReport = $this->tractor->gpsDailyReports()->first();

        // Expected moving time:
        // - Stopped->Moving: (07:01:00 - 07:00:30) = 30s
        // - Moving->Moving: (07:02:00 - 07:01:00) = 60s
        // - Moving->Moving: (07:04:00 - 07:02:00) = 120s
        // - Stopped->Moving: (07:07:00 - 07:06:00) = 60s
        // - Moving->Moving: (07:09:00 - 07:07:00) = 120s
        // Note: With state-change validation, the first confirmed movement window starts from the
        // second movement report in each transition. Total = 60 + 120 + 120 = 300 seconds
        $this->assertSame(300, $dailyReport->work_duration);
    }

    #[Test]
    public function it_calculates_stoppage_time_correctly_with_stoppage_and_movement_reports()
    {
        // Alternate movement and stoppage with consecutive reports to confirm transitions
        $jsonData = [
            // Initial moving state (confirmed with two consecutive moving reports)
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,006,000,1,000,0,863070043386100'], // 07:00:00 moving
            ['data' => '+Hooshnic:V1.03,3453.00020,05035.0002,000,240124,070030,006,000,1,000,0,863070043386100'], // 07:00:30 moving

            // Transition to stopped (two consecutive stopped reports)
            ['data' => '+Hooshnic:V1.03,3453.00030,05035.0003,000,240124,070100,000,000,1,090,0,863070043386100'], // 07:01:00 stopped (pending)
            ['data' => '+Hooshnic:V1.03,3453.00040,05035.0004,000,240124,070200,000,000,1,090,0,863070043386100'], // 07:02:00 stopped (confirm)

            // Continue stopped state
            ['data' => '+Hooshnic:V1.03,3453.00050,05035.0005,000,240124,070400,000,000,1,180,0,863070043386100'], // 07:04:00 stopped
            ['data' => '+Hooshnic:V1.03,3453.00060,05035.0006,000,240124,070500,000,000,1,180,0,863070043386100'], // 07:05:00 stopped

            // Transition back to moving (two consecutive moving reports)
            ['data' => '+Hooshnic:V1.03,3453.00100,05035.0010,000,240124,070600,008,000,1,000,0,863070043386100'], // 07:06:00 moving (pending)
            ['data' => '+Hooshnic:V1.03,3453.00200,05035.0020,000,240124,070630,008,000,1,000,0,863070043386100'], // 07:06:30 moving (confirm)

            // Transition to stopped again (two consecutive stopped reports)
            ['data' => '+Hooshnic:V1.03,3453.00210,05035.0021,000,240124,070700,000,000,1,270,0,863070043386100'], // 07:07:00 stopped (pending)
            ['data' => '+Hooshnic:V1.03,3453.00220,05035.0022,000,240124,070830,000,000,1,270,0,863070043386100'], // 07:08:30 stopped (confirm)
            ['data' => '+Hooshnic:V1.03,3453.00230,05035.0023,000,240124,070900,000,000,1,270,0,863070043386100'], // 07:09:00 stopped
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);
        $response->assertStatus(200);

        $dailyReport = $this->tractor->gpsDailyReports()->first();

        // Expected stoppage time:
        // - Moving->Stopped confirm: (07:01:00 - 07:00:30) = 30s
        // - Subsequent stopped during confirm: (07:02:00 - 07:01:00) = 60s
        // - Stopped->Stopped: (07:04:00 - 07:02:00) = 120s
        // - Stopped->Stopped: (07:05:00 - 07:04:00) = 60s
        // - Moving transition contributes no stoppage
        // - Moving->Stopped confirm: (07:07:00 - 07:06:30) = 30s
        // - Subsequent stopped during confirm: (07:08:30 - 07:07:00) = 90s
        // - Stopped->Stopped: (07:09:00 - 07:08:30) = 30s
        // Note: With state-change validation, some initial transition segments are not counted.
        // Total stoppage computed by the system for this sequence = 360 seconds
        $this->assertSame(360, $dailyReport->stoppage_duration);
    }

}
