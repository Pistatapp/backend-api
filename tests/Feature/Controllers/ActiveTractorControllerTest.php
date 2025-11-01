<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\Tractor;
use App\Models\GpsDevice;
use App\Models\GpsReport;
use App\Models\GpsMetricsCalculation;
use App\Models\User;
use App\Models\Farm;
use App\Models\Driver;
use App\Models\TractorTask;
use App\Models\Operation;
use App\Models\Plot;
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

        // Create a farm first
        $farm = Farm::factory()->create();
        $farm->tractors()->save($this->tractor);

        // Add user as farm admin
        $farm->admins()->attach($this->user->id);

        // Create a driver for the tractor
        Driver::factory()->create([
            'tractor_id' => $this->tractor->id,
            'farm_id' => $farm->id,
            'name' => 'John Doe',
            'mobile' => '09123456789'
        ]);

        // Create a daily report (total metrics)
        GpsMetricsCalculation::create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'traveled_distance' => 1.5,
            'work_duration' => 3600,
            'stoppage_count' => 2,
            'stoppage_duration' => 600,
            'average_speed' => 25,
            'efficiency' => 75.0,
            'tractor_task_id' => null
        ]);

        // Create task-based metrics for today
        $operation = Operation::factory()->create(['name' => 'Plowing']);
        $plot = Plot::factory()->create();
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_id' => $plot->id,
            'taskable_type' => Plot::class,
            'date' => today(),
            'start_time' => now()->subHour()->format('H:i'),
            'end_time' => now()->addHour()->format('H:i'),
            'status' => 'in_progress'
        ]);

        GpsMetricsCalculation::create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'traveled_distance' => 0.8,
            'work_duration' => 1800,
            'stoppage_count' => 1,
            'stoppage_duration' => 300,
            'average_speed' => 20,
            'efficiency' => 85.0,
            'tractor_task_id' => $task->id
        ]);

        // Create GPS reports for path testing
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

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_lists_active_tractors_for_a_farm()
    {
        $response = $this->getJson("/api/farms/{$this->tractor->farm_id}/tractors/active");

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
    public function it_can_get_tractor_path()
    {
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
    public function it_can_get_tractor_performance()
    {
        $response = $this->getJson("/api/tractors/{$this->tractor->id}/performance?date=" . jdate(today())->format('Y/m/d'));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'speed',
                    'status',
                    'traveled_distance',
                    'work_duration',
                    'stoppage_count',
                    'stoppage_duration',
                    'efficiencies' => [
                        'total',
                        'task-based'
                    ],
                    'driver' => [
                        'id',
                        'name',
                        'mobile'
                    ]
                ]
            ])
            ->assertJson([
                'data' => [
                    'efficiencies' => [
                        'total' => '75.00',
                        'task-based' => '85.00'
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_can_get_weekly_efficiency_chart()
    {
        // Create metrics for the last 7 days
        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);

            // Create daily metrics
            GpsMetricsCalculation::create([
                'tractor_id' => $this->tractor->id,
                'date' => $date,
                'traveled_distance' => 1.0 + $i,
                'work_duration' => 3600,
                'stoppage_count' => 1,
                'stoppage_duration' => 300,
                'average_speed' => 20 + $i,
                'efficiency' => 70.0 + $i,
                'tractor_task_id' => null
            ]);

            // Create task-based metrics
            GpsMetricsCalculation::create([
                'tractor_id' => $this->tractor->id,
                'date' => $date,
                'traveled_distance' => 0.5 + $i,
                'work_duration' => 1800,
                'stoppage_count' => 0,
                'stoppage_duration' => 0,
                'average_speed' => 15 + $i,
                'efficiency' => 80.0 + $i,
                'tractor_task_id' => 1 // Mock task ID
            ]);
        }

        $response = $this->getJson("/api/tractors/{$this->tractor->id}/weekly-efficiency-chart");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_efficiencies' => [
                        '*' => [
                            'efficiency',
                            'date'
                        ]
                    ],
                    'task_based_efficiencies' => [
                        '*' => [
                            'efficiency',
                            'date'
                        ]
                    ]
                ]
            ]);

        // Verify we have 7 days of data
        $responseData = $response->json('data');
        $this->assertCount(7, $responseData['total_efficiencies']);
        $this->assertCount(7, $responseData['task_based_efficiencies']);
    }
}
