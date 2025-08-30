<?php

namespace Tests\Feature;

use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TractorStartEndWorkingTimeDetectionTest extends TestCase
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
    public function it_detects_start_working_time_correctly()
    {
        // Sequence: stopped (0) → moving (>=2) with sustained movement to mark starting point
        $jsonData = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,000,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,003,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,070200,004,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.03000,05035.0300,000,240124,070300,004,000,1,180,0,863070043386100'],
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);
        $response->assertStatus(200);

        // There should be exactly one starting point marked for today
        $startingPoint = \App\Models\GpsReport::where('imei', '863070043386100')
            ->whereDate('date_time', today())
            ->where('is_starting_point', true)
            ->first();

        $this->assertNotNull($startingPoint, 'Failed asserting that a starting point was detected.');

        // The Tractor relation should resolve the same starting point
        $tractorStart = $this->tractor->startWorkingTime()->first();
        $this->assertNotNull($tractorStart, 'Failed asserting that tractor startWorkingTime relation returns a record.');
        $this->assertTrue($tractorStart->is($startingPoint), 'Tractor startWorkingTime does not match detected starting point.');
    }

    #[Test]
    public function it_detects_end_working_time_correctly()
    {
        // Ensure working hours encompass test times
        $this->tractor->update([
            'start_work_time' => '07:00',
            'end_work_time' => '17:00',
        ]);

        // Sequence: moving → moving → stopped to mark ending point
        $jsonData = [
            ['data' => '+Hooshnic:V1.03,3453.04000,05035.0400,000,240124,071000,015,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.05000,05035.0500,000,240124,071100,012,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.06000,05035.0600,000,240124,071200,000,000,1,270,0,863070043386100'],
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);
        $response->assertStatus(200);

        $endingPoint = \App\Models\GpsReport::where('imei', '863070043386100')
            ->whereDate('date_time', today())
            ->where('is_ending_point', true)
            ->first();

        $this->assertNotNull($endingPoint, 'Failed asserting that an ending point was detected.');

        $tractorEnd = $this->tractor->endWorkingTime()->first();
        $this->assertNotNull($tractorEnd, 'Failed asserting that tractor endWorkingTime relation returns a record.');
        $this->assertTrue($tractorEnd->is($endingPoint), 'Tractor endWorkingTime does not match detected ending point.');
    }

    #[Test]
    public function it_requires_sustained_movement_for_start_detection()
    {
        // Transition to moving but not sustained (falls below threshold immediately)
        $jsonData = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,072000,000,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,072100,003,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,072200,001,000,1,180,0,863070043386100'],
        ];

        $response = $this->postJson('/api/gps/reports', $jsonData);
        $response->assertStatus(200);

        $startingPoint = \App\Models\GpsReport::where('imei', '863070043386100')
            ->whereDate('date_time', today())
            ->where('is_starting_point', true)
            ->first();

        $this->assertNull($startingPoint, 'Start should not be detected without sustained movement.');
    }

    #[Test]
    public function it_detects_only_one_start_and_one_end_per_day()
    {
        $this->tractor->update([
            'start_work_time' => '07:00',
            'end_work_time' => '17:00',
        ]);

        // First start sequence
        $batch1 = [
            ['data' => '+Hooshnic:V1.03,3453.10000,05035.1000,000,240124,073000,000,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.11000,05035.1100,000,240124,073100,005,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.12000,05035.1200,000,240124,073200,006,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.13000,05035.1300,000,240124,073300,007,000,1,180,0,863070043386100'],
        ];
        $this->postJson('/api/gps/reports', $batch1)->assertStatus(200);

        // End sequence
        $batch2 = [
            ['data' => '+Hooshnic:V1.03,3453.14000,05035.1400,000,240124,080000,010,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.15000,05035.1500,000,240124,080100,012,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.16000,05035.1600,000,240124,080200,000,000,1,270,0,863070043386100'],
        ];
        $this->postJson('/api/gps/reports', $batch2)->assertStatus(200);

        // Attempt second start sequence later in the day — should not create another starting point
        $batch3 = [
            ['data' => '+Hooshnic:V1.03,3453.17000,05035.1700,000,240124,090000,000,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.18000,05035.1800,000,240124,090100,005,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.19000,05035.1900,000,240124,090200,006,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.20000,05035.2000,000,240124,090300,007,000,1,180,0,863070043386100'],
        ];
        $this->postJson('/api/gps/reports', $batch3)->assertStatus(200);

        $startCount = \App\Models\GpsReport::where('imei', '863070043386100')
            ->whereDate('date_time', today())
            ->where('is_starting_point', true)
            ->count();

        $endCount = \App\Models\GpsReport::where('imei', '863070043386100')
            ->whereDate('date_time', today())
            ->where('is_ending_point', true)
            ->count();

        $this->assertEquals(1, $startCount, 'There should be only one starting point per day.');
        $this->assertEquals(1, $endCount, 'There should be only one ending point per day.');
    }

    #[Test]
    public function it_ignores_movement_before_start_work_time()
    {
        // Set start work time to 08:00
        $this->tractor->update([
            'start_work_time' => '08:00',
            'end_work_time' => '17:00',
        ]);

        // Movement sequence that happens before start_work_time (GPS 04:00 → Local 07:30)
        $earlyMovementData = [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,040000,000,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,040100,005,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,040200,006,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.03000,05035.0300,000,240124,040300,007,000,1,180,0,863070043386100'],
        ];

        $response = $this->postJson('/api/gps/reports', $earlyMovementData);
        $response->assertStatus(200);

        // No starting point should be detected yet since it's before start_work_time
        $startingPoint = \App\Models\GpsReport::where('imei', '863070043386100')
            ->whereDate('date_time', today())
            ->where('is_starting_point', true)
            ->first();

        $this->assertNull($startingPoint, 'Starting point should not be detected before start_work_time.');

        // Now movement sequence that happens after start_work_time (GPS 05:00 → Local 08:30)
        $validMovementData = [
            ['data' => '+Hooshnic:V1.03,3453.04000,05035.0400,000,240124,050000,000,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.05000,05035.0500,000,240124,050100,005,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.06000,05035.0600,000,240124,050200,006,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.07000,05035.0700,000,240124,050300,007,000,1,180,0,863070043386100'],
        ];

        $response = $this->postJson('/api/gps/reports', $validMovementData);
        $response->assertStatus(200);

        // Now a starting point should be detected
        $startingPoint = \App\Models\GpsReport::where('imei', '863070043386100')
            ->whereDate('date_time', today())
            ->where('is_starting_point', true)
            ->first();

        $this->assertNotNull($startingPoint, 'Starting point should be detected after start_work_time.');

        // The starting point should be from the second batch (after 08:00)
        $this->assertTrue($startingPoint->date_time->gte(\Carbon\Carbon::parse('2024-01-24 08:00:00')),
            'Starting point should be after the start_work_time.');
    }
}
