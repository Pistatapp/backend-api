<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\Tractor;
use App\Models\GpsDevice;
use App\Models\GpsReport;
use App\Models\GpsDailyReport;
use App\Models\User;
use App\Models\Farm;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class ActiveTractorControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tractor $tractor;
    private GpsDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

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

        // Create a daily report
        GpsDailyReport::create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'traveled_distance' => 1.5,
            'work_duration' => 3600,
            'stoppage_count' => 2,
            'stoppage_duration' => 600,
            'efficiency' => 75.0
        ]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_lists_active_tractors_for_a_farm()
    {
        $farm = Farm::factory()->create();
        $farm->tractors()->save($this->tractor);

        $driver = Driver::factory()->create([
            'tractor_id' => $this->tractor->id,
            'name' => 'John Doe',
            'mobile' => '09123456789'
        ]);

        $response = $this->getJson("/api/farms/{$farm->id}/tractors/active");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'gps_device' => [
                            'id',
                            'imei'
                        ],
                        'driver' => [
                            'id',
                            'name',
                            'mobile'
                        ],
                        'status',
                        'start_working_time',
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_applies_kalman_filter_to_gps_coordinates()
    {
        // Create some GPS reports with slightly noisy coordinates
        $reports = [
            [
                'coordinate' => [34.884065, 50.599625],
                'speed' => 20,
                'status' => 1,
                'date_time' => now()->subMinutes(2),
            ],
            [
                'coordinate' => [34.884067, 50.599627], // Slightly noisy
                'speed' => 20,
                'status' => 1,
                'date_time' => now()->subMinute(),
            ],
            [
                'coordinate' => [34.884066, 50.599626], // More noise
                'speed' => 20,
                'status' => 1,
                'date_time' => now(),
            ]
        ];

        foreach ($reports as $report) {
            GpsReport::create(array_merge($report, [
                'gps_device_id' => $this->device->id,
                'imei' => $this->device->imei,
                'is_stopped' => false,
                'stoppage_time' => 0,
                'is_starting_point' => false,
                'is_ending_point' => false,
            ]));
        }

        $response = $this->getJson("/api/tractors/{$this->tractor->id}/reports?date=" . jdate(today())->format('Y/m/d'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'speed',
                    'status',
                    'start_working_time',
                    'traveled_distance',
                    'work_duration',
                    'stoppage_count',
                    'stoppage_duration',
                    'efficiency',
                    'points' => [
                        '*' => [
                            'latitude',
                            'longitude',
                            'speed',
                            'status',
                            'is_starting_point',
                            'is_ending_point',
                            'is_stopped'
                        ]
                    ],
                    'current_task'
                ]
            ]);

        $points = $response->json('data.points');

        // Check that coordinates were smoothed
        // The middle point should have coordinates between its neighbors
        $this->assertCount(3, $points);
        $this->assertNotEquals(34.884067, $points[1]['latitude']);
        $this->assertNotEquals(50.599627, $points[1]['longitude']);

        // Verify the smoothed coordinates are between the original values
        $this->assertGreaterThan(34.884065, $points[1]['latitude']);
        $this->assertLessThan(34.884067, $points[1]['latitude']);
        $this->assertGreaterThan(50.599625, $points[1]['longitude']);
        $this->assertLessThan(50.599627, $points[1]['longitude']);
    }

    #[Test]
    public function it_can_get_tractor_path()
    {
        GpsReport::create([
            'gps_device_id' => $this->device->id,
            'imei' => $this->device->imei,
            'coordinate' => [34.884065, 50.599625],
            'speed' => 20,
            'status' => 1,
            'date_time' => now(),
            'is_stopped' => false,
            'stoppage_time' => 0,
            'is_starting_point' => true,
            'is_ending_point' => false,
        ]);

        $response = $this->getJson("/api/tractors/{$this->tractor->id}/path?date=" . jdate(today())->format('Y/m/d'));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'latitude',
                        'longitude',
                        'speed',
                        'status',
                        'is_starting_point',
                        'is_ending_point',
                        'is_stopped',
                        'stoppage_time',
                        'date_time'
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_can_get_tractor_details()
    {
        $response = $this->getJson("/api/tractors/{$this->tractor->id}/details?date=" . jdate(today())->format('Y/m/d'));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'last_seven_days_efficiency' => [
                        '*' => [
                            'date',
                            'efficiency'
                        ]
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_validates_date_parameter()
    {
        $response = $this->getJson("/api/tractors/{$this->tractor->id}/reports");
        $response->assertUnprocessable()->assertJsonValidationErrors(['date']);

        $response = $this->getJson("/api/tractors/{$this->tractor->id}/path");
        $response->assertUnprocessable()->assertJsonValidationErrors(['date']);

        $response = $this->getJson("/api/tractors/{$this->tractor->id}/details");
        $response->assertUnprocessable()->assertJsonValidationErrors(['date']);
    }

    #[Test]
    public function it_returns_empty_points_when_no_reports_exist()
    {
        GpsReport::where('gps_device_id', $this->device->id)->delete();

        $response = $this->getJson("/api/tractors/{$this->tractor->id}/reports?date=" . jdate(today())->format('Y/m/d'));

        $response->assertOk()
            ->assertJsonPath('data.points', []);
    }
}
