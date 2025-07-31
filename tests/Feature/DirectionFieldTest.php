<?php

namespace Tests\Feature;

use App\Models\Tractor;
use App\Models\GpsReport;
use App\Models\GpsDevice;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class DirectionFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2024-01-24 07:02:00');
    }

    public function test_direction_field_is_saved_correctly()
    {
        // Create a user, tractor, and GPS device
        $user = User::factory()->create();
        $tractor = Tractor::factory()->create();
        $device = GpsDevice::factory()->create([
            'user_id' => $user->id,
            'tractor_id' => $tractor->id,
            'imei' => '863070043386100'
        ]);

        // Send GPS report with specific direction value
        $response = $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070000,020,000,1,270,0,863070043386100']
        ]);

        $response->assertStatus(200);

        // Verify the GPS report was created with the correct direction
        $gpsReport = GpsReport::where('imei', '863070043386100')->first();
        $this->assertNotNull($gpsReport);
        $this->assertEquals(270, $gpsReport->ew_direction);
        $this->assertEquals('863070043386100', $gpsReport->imei);
        $this->assertEquals(1, $gpsReport->status);
        $this->assertEquals(20, $gpsReport->speed);
    }

    public function test_different_direction_values()
    {
        // Create a user, tractor, and GPS device
        $user = User::factory()->create();
        $tractor = Tractor::factory()->create();
        $device = GpsDevice::factory()->create([
            'user_id' => $user->id,
            'tractor_id' => $tractor->id,
            'imei' => '863070043386100'
        ]);

        // Send multiple GPS reports with different direction values
        $response = $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070000,020,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04394,05035.9776,000,240124,070100,020,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04395,05035.9777,000,240124,070200,020,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04396,05035.9778,000,240124,070300,020,000,1,270,0,863070043386100'],
        ]);

        $response->assertStatus(200);

        // Verify all reports were created with correct directions
        $reports = GpsReport::where('imei', '863070043386100')->orderBy('created_at')->get();

        $this->assertCount(4, $reports);
        $this->assertEquals(0, $reports[0]->ew_direction);
        $this->assertEquals(90, $reports[1]->ew_direction);
        $this->assertEquals(180, $reports[2]->ew_direction);
        $this->assertEquals(270, $reports[3]->ew_direction);
    }
}
