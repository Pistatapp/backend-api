<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use App\Models\Field;
use App\Models\Operation;
use App\Models\TractorTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\WithFaker;

class MultiDeviceGpsMetricsTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private array $tractors;
    private array $devices;
    private Field $field;
    private Operation $operation;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2024-01-24 07:02:00');
        Cache::flush();

        // Create user
        $this->user = User::factory()->create();

        // Create field and operation
        $this->field = Field::factory()->create([
            'coordinates' => [
                [34.53, 50.35],
                [34.53, 50.36],
                [34.54, 50.36],
                [34.54, 50.35],
                [34.53, 50.35]
            ]
        ]);

        $this->operation = Operation::factory()->create();

        // Create multiple tractors with different working hours
        $this->tractors = [
            Tractor::factory()->create([
                'start_work_time' => '05:00', // 2 hours before current time (07:02)
                'end_work_time' => '15:00', // 8 hours after current time
                'expected_daily_work_time' => 8,
            ]),
            Tractor::factory()->create([
                'start_work_time' => '06:00', // 1 hour before current time
                'end_work_time' => '13:00', // 6 hours after current time
                'expected_daily_work_time' => 6,
            ]),
            Tractor::factory()->create([
                'start_work_time' => '04:00', // 3 hours before current time
                'end_work_time' => '17:00', // 10 hours after current time
                'expected_daily_work_time' => 10,
            ])
        ];

        // Create GPS devices for each tractor
        $this->devices = [
            GpsDevice::factory()->create([
                'user_id' => $this->user->id,
                'tractor_id' => $this->tractors[0]->id,
                'imei' => '863070043386100'
            ]),
            GpsDevice::factory()->create([
                'user_id' => $this->user->id,
                'tractor_id' => $this->tractors[1]->id,
                'imei' => '863070043386101'
            ]),
            GpsDevice::factory()->create([
                'user_id' => $this->user->id,
                'tractor_id' => $this->tractors[2]->id,
                'imei' => '863070043386102'
            ])
        ];
    }

    #[Test]
    public function it_calculates_metrics_correctly_for_multiple_devices_without_tasks()
    {
        // Simulate GPS data for each device
        $deviceData = [
            // Device 1: Moving in a straight line
            [
                'imei' => '863070043386100',
                'reports' => [
                    ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,010,000,1,000,0,863070043386100'],
                    ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,010,000,1,090,0,863070043386100'],
                    ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,070200,020,000,1,180,0,863070043386100'],
                ]
            ],
            // Device 2: Moving with stoppages
            [
                'imei' => '863070043386101',
                'reports' => [
                    ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,006,000,1,000,0,863070043386101'],
                    ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,006,000,1,090,0,863070043386101'],
                    ['data' => '+Hooshnic:V1.03,3453.01010,05035.0101,000,240124,070200,000,000,1,180,0,863070043386101'],
                    ['data' => '+Hooshnic:V1.03,3453.01020,05035.0102,000,240124,070300,000,000,1,180,0,863070043386101'],
                    ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,070400,008,000,1,000,0,863070043386101'],
                ]
            ],
            // Device 3: Complex movement pattern
            [
                'imei' => '863070043386102',
                'reports' => [
                    ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,005,000,1,000,0,863070043386102'],
                    ['data' => '+Hooshnic:V1.03,3453.00500,05035.0050,000,240124,070100,005,000,1,090,0,863070043386102'],
                    ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070200,005,000,1,180,0,863070043386102'],
                    ['data' => '+Hooshnic:V1.03,3453.01010,05035.0101,000,240124,070300,000,000,1,270,0,863070043386102'],
                    ['data' => '+Hooshnic:V1.03,3453.01020,05035.0102,000,240124,070400,000,000,1,270,0,863070043386102'],
                    ['data' => '+Hooshnic:V1.03,3453.01500,05035.0150,000,240124,070500,007,000,1,000,0,863070043386102'],
                ]
            ]
        ];

        // Send data for each device
        foreach ($deviceData as $data) {
            $response = $this->postJson('/api/gps/reports', $data['reports']);
            $response->assertStatus(200);
        }

        // Verify metrics for each device
        $this->verifyDeviceMetrics($this->tractors[0], 0.048, 120, 0, 0); // Device 1: 2 minutes moving, no stoppages
        $this->verifyDeviceMetrics($this->tractors[1], 0.048, 120, 120, 1); // Device 2: 2 minutes moving, 2 minutes stopped, 1 stoppage
        $this->verifyDeviceMetrics($this->tractors[2], 0.036, 180, 120, 1); // Device 3: 3 minutes moving, 2 minutes stopped, 1 stoppage
    }

    #[Test]
    public function it_calculates_metrics_correctly_for_multiple_devices_with_tasks()
    {
        // Create tasks for each tractor
        $tasks = [];
        foreach ($this->tractors as $index => $tractor) {
            $tasks[$index] = TractorTask::factory()->create([
                'tractor_id' => $tractor->id,
                'operation_id' => $this->operation->id,
                'taskable_type' => Field::class,
                'taskable_id' => $this->field->id,
                'date' => today(),
                'start_time' => now()->subHours(1),
                'end_time' => now()->addHours(8),
                'status' => 'started'
            ]);
        }

        // Simulate GPS data within task areas
        $deviceData = [
            // Device 1: Moving within task area
            [
                'imei' => '863070043386100',
                'reports' => [
                    ['data' => '+Hooshnic:V1.03,3431.80000,05021.0000,000,240124,070000,006,000,1,000,0,863070043386100'],
                    ['data' => '+Hooshnic:V1.03,3431.81000,05021.0100,000,240124,070100,006,000,1,090,0,863070043386100'],
                    ['data' => '+Hooshnic:V1.03,3431.82000,05021.0200,000,240124,070200,006,000,1,180,0,863070043386100'],
                ]
            ],
            // Device 2: Moving and stopping within task area
            [
                'imei' => '863070043386101',
                'reports' => [
                    ['data' => '+Hooshnic:V1.03,3431.80000,05021.0000,000,240124,070000,006,000,1,000,0,863070043386101'],
                    ['data' => '+Hooshnic:V1.03,3431.81000,05021.0100,000,240124,070100,006,000,1,090,0,863070043386101'],
                    ['data' => '+Hooshnic:V1.03,3431.81010,05021.0101,000,240124,070200,000,000,1,180,0,863070043386101'],
                    ['data' => '+Hooshnic:V1.03,3431.81020,05021.0102,000,240124,070300,000,000,1,180,0,863070043386101'],
                    ['data' => '+Hooshnic:V1.03,3431.82000,05021.0200,000,240124,070400,008,000,1,000,0,863070043386101'],
                ]
            ],
            // Device 3: Complex movement within task area
            [
                'imei' => '863070043386102',
                'reports' => [
                    ['data' => '+Hooshnic:V1.03,3431.80000,05021.0000,000,240124,070000,005,000,1,000,0,863070043386102'],
                    ['data' => '+Hooshnic:V1.03,3431.80500,05021.0050,000,240124,070100,005,000,1,090,0,863070043386102'],
                    ['data' => '+Hooshnic:V1.03,3431.81000,05021.0100,000,240124,070200,005,000,1,180,0,863070043386102'],
                    ['data' => '+Hooshnic:V1.03,3431.81010,05021.0101,000,240124,070300,000,000,1,270,0,863070043386102'],
                    ['data' => '+Hooshnic:V1.03,3431.81020,05021.0102,000,240124,070400,000,000,1,270,0,863070043386102'],
                    ['data' => '+Hooshnic:V1.03,3431.81500,05021.0150,000,240124,070500,007,000,1,000,0,863070043386102'],
                ]
            ]
        ];

        // Send data for each device
        foreach ($deviceData as $data) {
            $response = $this->postJson('/api/gps/reports', $data['reports']);
            $response->assertStatus(200);
        }

        // Verify task-specific metrics for each device
        foreach ($this->tractors as $index => $tractor) {
            $taskReport = $tractor->gpsDailyReports()
                ->where('tractor_task_id', $tasks[$index]->id)
                ->where('date', today())
                ->first();

            $this->assertNotNull($taskReport, "Task-specific daily report not found for tractor {$index}");
            $this->assertGreaterThan(0, $taskReport->traveled_distance, "Traveled distance should be greater than 0 for tractor {$index}");
            $this->assertGreaterThan(0, $taskReport->work_duration, "Work duration should be greater than 0 for tractor {$index}");
        }
    }

    #[Test]
    public function it_handles_concurrent_requests_from_multiple_devices()
    {
        // Simulate concurrent requests from multiple devices
        $responses = [];

        // Device 1 sends data
        $responses[] = $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,006,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,006,000,1,090,0,863070043386100'],
        ]);

        // Device 2 sends data immediately after
        $responses[] = $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,006,000,1,000,0,863070043386101'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,006,000,1,090,0,863070043386101'],
        ]);

        // Device 3 sends data immediately after
        $responses[] = $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,006,000,1,000,0,863070043386102'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,006,000,1,090,0,863070043386102'],
        ]);

        // All responses should be successful
        foreach ($responses as $response) {
            $response->assertStatus(200);
        }

        // Verify each device has its own daily report
        foreach ($this->tractors as $tractor) {
            $dailyReport = $tractor->gpsDailyReports()->where('date', today())->first();
            $this->assertNotNull($dailyReport, "Daily report not found for tractor {$tractor->id}");
            $this->assertGreaterThan(0, $dailyReport->traveled_distance, "Traveled distance should be greater than 0 for tractor {$tractor->id}");
        }
    }

    #[Test]
    public function it_handles_devices_sending_data_outside_working_hours()
    {
        // Set working hours that don't include current time
        $this->tractors[0]->update([
            'start_work_time' => now()->addHours(2)->format('H:i'),
            'end_work_time' => now()->addHours(10)->format('H:i'),
        ]);

        // Send GPS data outside working hours (using UTC times that will be 3.5 hours behind after conversion)
        $response = $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,033000,006,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,033100,006,000,1,090,0,863070043386100'],
        ]);

        $response->assertStatus(200);

        // Verify that metrics are not calculated (should be 0)
        $dailyReport = $this->tractors[0]->gpsDailyReports()->where('date', today())->first();
        $this->assertNotNull($dailyReport, "Daily report should be created even outside working hours");
        $this->assertEquals(0, $dailyReport->traveled_distance, "Traveled distance should be 0 outside working hours");
        $this->assertEquals(0, $dailyReport->work_duration, "Work duration should be 0 outside working hours");
    }

    #[Test]
    public function it_handles_devices_with_mixed_data_inside_and_outside_working_hours()
    {
        // Set working hours
        $this->tractors[0]->update([
            'start_work_time' => '06:02', // 1 hour before current time (07:02)
            'end_work_time' => '08:02', // 1 hour after current time
        ]);

        // Clear cache to ensure working hours are updated
        Cache::flush();

        // Send data spanning working hours (adjusting UTC times to account for 3.5 hour offset)
        $response = $this->postJson('/api/gps/reports', [
            // Outside working hours (should not be counted) - UTC 02:30:00 becomes 06:00:00 Tehran time
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,023000,006,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,023100,006,000,1,090,0,863070043386100'],
            // Inside working hours (should be counted) - UTC 03:30:00 becomes 07:00:00 Tehran time
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,240124,033000,006,000,1,180,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.03000,05035.0300,000,240124,033100,006,000,1,270,0,863070043386100'],
        ]);

        $response->assertStatus(200);

        // Verify that only data within working hours is counted
        $dailyReport = $this->tractors[0]->gpsDailyReports()->where('date', today())->first();
        $this->assertNotNull($dailyReport, "Daily report should be created");

        // Should only count the movement between 07:00:00 and 07:01:00 (60 seconds)
        $this->assertEquals(60, $dailyReport->work_duration, "Only work duration within working hours should be counted");
        $this->assertGreaterThan(0, $dailyReport->traveled_distance, "Traveled distance within working hours should be counted");
    }

    #[Test]
    public function it_handles_single_report_outside_working_hours()
    {
        // Set working hours that don't include current time
        $this->tractors[0]->update([
            'start_work_time' => '08:00', // After current time (07:02)
            'end_work_time' => '16:00',
        ]);

        // Clear cache
        Cache::flush();

        // Send GPS data outside working hours (using UTC times that will be 3.5 hours behind after conversion)
        $response = $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,040000,006,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,040100,006,000,1,090,0,863070043386100'],
        ]);

        $response->assertStatus(200);

        // Verify that metrics are not calculated (should be 0)
        $dailyReport = $this->tractors[0]->gpsDailyReports()->where('date', today())->first();
        $this->assertNotNull($dailyReport, "Daily report should be created even outside working hours");
        $this->assertEquals(0, $dailyReport->traveled_distance, "Traveled distance should be 0 outside working hours");
        $this->assertEquals(0, $dailyReport->work_duration, "Work duration should be 0 outside working hours");
    }

    #[Test]
    public function it_handles_cache_invalidation_between_devices()
    {
        // Send data for device 1 in a single request
        $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,006,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,006,000,1,090,0,863070043386100'],
        ]);

        // Clear cache to simulate cache invalidation
        Cache::flush();

        // Send data for device 2 in a single request
        $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,006,000,1,000,0,863070043386101'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,006,000,1,090,0,863070043386101'],
        ]);

        // Verify both devices have correct metrics
        $dailyReport1 = $this->tractors[0]->gpsDailyReports()->where('date', today())->first();
        $dailyReport2 = $this->tractors[1]->gpsDailyReports()->where('date', today())->first();

        $this->assertNotNull($dailyReport1, "Daily report for device 1 should exist");
        $this->assertNotNull($dailyReport2, "Daily report for device 2 should exist");
        $this->assertGreaterThan(0, $dailyReport1->traveled_distance, "Device 1 should have traveled distance");
        $this->assertGreaterThan(0, $dailyReport2->traveled_distance, "Device 2 should have traveled distance");
    }

    #[Test]
    public function it_handles_devices_with_different_time_zones()
    {
        // Test with different time zones by adjusting the GPS data timestamps
        $response = $this->postJson('/api/gps/reports', [
            // Data with different time but same date
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,080000,006,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,080100,006,000,1,090,0,863070043386100'],
        ]);

        $response->assertStatus(200);

        $dailyReport = $this->tractors[0]->gpsDailyReports()->where('date', today())->first();
        $this->assertNotNull($dailyReport, "Daily report should be created for different time");
        $this->assertGreaterThan(0, $dailyReport->traveled_distance, "Traveled distance should be calculated for different time");
    }

    #[Test]
    public function it_handles_devices_with_invalid_gps_data()
    {
        // Send invalid GPS data
        $response = $this->postJson('/api/gps/reports', [
            ['data' => 'invalid_gps_data'],
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,006,000,1,000,0,863070043386100'],
        ]);

        $response->assertStatus(200);

        // Verify that valid data is still processed
        $dailyReport = $this->tractors[0]->gpsDailyReports()->where('date', today())->first();
        $this->assertNotNull($dailyReport, "Daily report should be created even with invalid data");
    }

    #[Test]
    public function it_handles_devices_with_duplicate_reports()
    {
        // Send duplicate reports
        $response = $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,006,000,1,000,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,240124,070000,006,000,1,000,0,863070043386100'], // Duplicate
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,240124,070100,006,000,1,090,0,863070043386100'],
        ]);

        $response->assertStatus(200);

        // Verify metrics are calculated correctly (duplicates should be handled)
        $dailyReport = $this->tractors[0]->gpsDailyReports()->where('date', today())->first();
        $this->assertNotNull($dailyReport, "Daily report should be created");
        $this->assertGreaterThan(0, $dailyReport->traveled_distance, "Traveled distance should be calculated correctly");
    }

    /**
     * Helper method to verify device metrics
     */
    private function verifyDeviceMetrics(Tractor $tractor, float $expectedDistance, int $expectedWorkDuration, int $expectedStoppageDuration, int $expectedStoppageCount): void
    {
        $dailyReport = $tractor->gpsDailyReports()->where('date', today())->first();

        $this->assertNotNull($dailyReport, "Daily report not found for tractor {$tractor->id}");
        $this->assertEqualsWithDelta($expectedDistance, $dailyReport->traveled_distance, 0.01, "Traveled distance mismatch for tractor {$tractor->id}");
        $this->assertEquals($expectedWorkDuration, $dailyReport->work_duration, "Work duration mismatch for tractor {$tractor->id}");
        $this->assertEquals($expectedStoppageDuration, $dailyReport->stoppage_duration, "Stoppage duration mismatch for tractor {$tractor->id}");
        $this->assertEquals($expectedStoppageCount, $dailyReport->stoppage_count, "Stoppage count mismatch for tractor {$tractor->id}");
    }
}
