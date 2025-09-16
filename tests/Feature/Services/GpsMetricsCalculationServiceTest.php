<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\GpsMetricsCalculationService;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Models\GpsMetricsCalculation;
use App\Models\Field;
use App\Models\Farm;
use App\Models\Operation;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GpsMetricsCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tractor $tractor;
    private GpsMetricsCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tractor = Tractor::factory()->create([
            'expected_daily_work_time' => 8 // 8 hours
        ]);

        $this->service = new GpsMetricsCalculationService($this->tractor, null);
    }

    #[Test]
    public function it_creates_daily_report_when_none_exists()
    {
        $dailyReport = $this->service->fetchOrCreate();

        $this->assertInstanceOf(GpsMetricsCalculation::class, $dailyReport);
        $this->assertEquals($this->tractor->id, $dailyReport->tractor_id);
        $this->assertNull($dailyReport->tractor_task_id);
        $this->assertEquals(today()->toDateString(), $dailyReport->date);

        // Check default values
        $this->assertEquals(0, $dailyReport->traveled_distance);
        $this->assertEquals(0, $dailyReport->work_duration);
        $this->assertEquals(0, $dailyReport->stoppage_duration);
        $this->assertEquals(0, $dailyReport->stoppage_count);
        $this->assertEquals(0, $dailyReport->efficiency);
        $this->assertEquals(0, $dailyReport->max_speed);
        $this->assertEquals(0, $dailyReport->average_speed);
    }

    #[Test]
    public function it_returns_existing_daily_report()
    {
        // Create an existing daily report
        $existingReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => null,
            'date' => today()->toDateString(),
            'traveled_distance' => 100.0,
            'work_duration' => 3600,
            'stoppage_duration' => 600,
            'stoppage_count' => 5,
            'efficiency' => 12.5,
            'max_speed' => 25,
            'average_speed' => 20.0
        ]);

        $dailyReport = $this->service->fetchOrCreate();

        $this->assertEquals($existingReport->id, $dailyReport->id);
        $this->assertEquals(100.0, $dailyReport->traveled_distance);
        $this->assertEquals(3600, $dailyReport->work_duration);
        $this->assertEquals(600, $dailyReport->stoppage_duration);
        $this->assertEquals(5, $dailyReport->stoppage_count);
        $this->assertEquals(12.5, $dailyReport->efficiency);
        $this->assertEquals(25, $dailyReport->max_speed);
        $this->assertEquals(20.0, $dailyReport->average_speed);
    }

    #[Test]
    public function it_creates_daily_report_with_task()
    {
        // Create a farm and field
        $farm = Farm::factory()->create();
        $field = Field::factory()->create(['farm_id' => $farm->id]);
        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $service = new GpsMetricsCalculationService($this->tractor, $task);
        $dailyReport = $service->fetchOrCreate();

        $this->assertEquals($this->tractor->id, $dailyReport->tractor_id);
        $this->assertEquals($task->id, $dailyReport->tractor_task_id);
        $this->assertEquals(today()->toDateString(), $dailyReport->date);
    }

    #[Test]
    public function it_updates_daily_report_with_new_data()
    {
        // Create an existing daily report
        $dailyReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'traveled_distance' => 50.0,
            'work_duration' => 1800, // 30 minutes
            'stoppage_duration' => 300, // 5 minutes
            'stoppage_count' => 2,
            'efficiency' => 6.25,
            'max_speed' => 15,
            'average_speed' => 20.0
        ]);

        $newData = [
            'totalTraveledDistance' => 25.0,
            'totalMovingTime' => 900, // 15 minutes
            'totalStoppedTime' => 180, // 3 minutes
            'stoppageCount' => 1,
            'maxSpeed' => 20
        ];

        $result = $this->service->update($dailyReport, $newData);

        // Check that the report was updated
        $dailyReport->refresh();

        $this->assertEquals(75.0, $dailyReport->traveled_distance); // 50 + 25
        $this->assertEquals(2700, $dailyReport->work_duration); // 1800 + 900
        $this->assertEquals(480, $dailyReport->stoppage_duration); // 300 + 180
        $this->assertEquals(3, $dailyReport->stoppage_count); // 2 + 1
        $this->assertEquals(20, $dailyReport->max_speed); // Updated max speed

        // Check returned data
        $this->assertEquals(75.0, $result['traveled_distance']);
        $this->assertEquals(2700, $result['work_duration']);
        $this->assertEquals(480, $result['stoppage_duration']);
        $this->assertEquals(3, $result['stoppage_count']);
        $this->assertEquals(20, $result['max_speed']);
    }

    #[Test]
    public function it_calculates_efficiency_correctly()
    {
        $dailyReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'efficiency' => 0
        ]);

        $newData = [
            'totalTraveledDistance' => 0,
            'totalMovingTime' => 14400, // 4 hours
            'totalStoppedTime' => 0,
            'stoppageCount' => 0,
            'maxSpeed' => 0
        ];

        $this->service->update($dailyReport, $newData);

        $dailyReport->refresh();

        // Efficiency = (moving_time / expected_daily_work_time) * 100
        // = (14400 / (8 * 3600)) * 100 = (14400 / 28800) * 100 = 50%
        $this->assertEquals(50.0, $dailyReport->efficiency, '', 0.01);
    }

    #[Test]
    public function it_calculates_average_speed_correctly()
    {
        $dailyReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'traveled_distance' => 0,
            'work_duration' => 0,
            'average_speed' => 0
        ]);

        $newData = [
            'totalTraveledDistance' => 60.0, // 60 km
            'totalMovingTime' => 7200, // 2 hours
            'totalStoppedTime' => 0,
            'stoppageCount' => 0,
            'maxSpeed' => 0
        ];

        $this->service->update($dailyReport, $newData);

        $dailyReport->refresh();

        // Average speed = traveled_distance / (work_duration / 3600)
        // = 60 / (7200 / 3600) = 60 / 2 = 30 km/h
        $this->assertEquals(30.0, $dailyReport->average_speed, '', 0.01);
    }

    #[Test]
    public function it_handles_zero_work_duration_for_average_speed()
    {
        $dailyReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'traveled_distance' => 0,
            'work_duration' => 0,
            'average_speed' => 0
        ]);

        $newData = [
            'totalTraveledDistance' => 0,
            'totalMovingTime' => 0,
            'totalStoppedTime' => 0,
            'stoppageCount' => 0,
            'maxSpeed' => 0
        ];

        $this->service->update($dailyReport, $newData);

        $dailyReport->refresh();

        // Average speed should be 0 when work_duration is 0
        $this->assertEquals(0, $dailyReport->average_speed);
    }

    #[Test]
    public function it_handles_multiple_updates()
    {
        $dailyReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'traveled_distance' => 0,
            'work_duration' => 0,
            'stoppage_duration' => 0,
            'stoppage_count' => 0,
            'efficiency' => 0,
            'max_speed' => 0,
            'average_speed' => 0
        ]);

        // First update
        $firstData = [
            'totalTraveledDistance' => 10.0,
            'totalMovingTime' => 1800, // 30 minutes
            'totalStoppedTime' => 300, // 5 minutes
            'stoppageCount' => 1,
            'maxSpeed' => 15
        ];

        $this->service->update($dailyReport, $firstData);

        // Second update
        $secondData = [
            'totalTraveledDistance' => 20.0,
            'totalMovingTime' => 3600, // 1 hour
            'totalStoppedTime' => 600, // 10 minutes
            'stoppageCount' => 2,
            'maxSpeed' => 25
        ];

        $this->service->update($dailyReport, $secondData);

        $dailyReport->refresh();

        // Check cumulative values
        $this->assertEquals(30.0, $dailyReport->traveled_distance); // 10 + 20
        $this->assertEquals(5400, $dailyReport->work_duration); // 1800 + 3600
        $this->assertEquals(900, $dailyReport->stoppage_duration); // 300 + 600
        $this->assertEquals(3, $dailyReport->stoppage_count); // 1 + 2
        $this->assertEquals(25, $dailyReport->max_speed); // Latest max speed
    }

    #[Test]
    public function it_handles_different_expected_work_times()
    {
        // Set different expected work time
        $this->tractor->update(['expected_daily_work_time' => 6]); // 6 hours

        $dailyReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'efficiency' => 0
        ]);

        $newData = [
            'totalTraveledDistance' => 0,
            'totalMovingTime' => 10800, // 3 hours
            'totalStoppedTime' => 0,
            'stoppageCount' => 0,
            'maxSpeed' => 0
        ];

        $this->service->update($dailyReport, $newData);

        $dailyReport->refresh();

        // Efficiency = (moving_time / expected_daily_work_time) * 100
        // = (10800 / (6 * 3600)) * 100 = (10800 / 21600) * 100 = 50%
        $this->assertEquals(50.0, $dailyReport->efficiency, '', 0.01);
    }

    #[Test]
    public function it_handles_very_high_efficiency()
    {
        $dailyReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'efficiency' => 0
        ]);

        $newData = [
            'totalTraveledDistance' => 0,
            'totalMovingTime' => 28800, // 8 hours (100% of expected)
            'totalStoppedTime' => 0,
            'stoppageCount' => 0,
            'maxSpeed' => 0
        ];

        $this->service->update($dailyReport, $newData);

        $dailyReport->refresh();

        // Efficiency should be 100%
        $this->assertEquals(100.0, $dailyReport->efficiency, '', 0.01);
    }

    #[Test]
    public function it_handles_negative_values_gracefully()
    {
        $dailyReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'traveled_distance' => 100.0,
            'work_duration' => 3600,
            'stoppage_duration' => 600,
            'stoppage_count' => 5,
            'efficiency' => 12.5,
            'max_speed' => 25,
            'average_speed' => 20.0
        ]);

        $newData = [
            'totalTraveledDistance' => -10.0, // Negative distance
            'totalMovingTime' => -300, // Negative time
            'totalStoppedTime' => -60, // Negative stoppage time
            'stoppageCount' => -1, // Negative count
            'maxSpeed' => 0
        ];

        $result = $this->service->update($dailyReport, $newData);

        $dailyReport->refresh();

        // Values should be updated even if negative (business logic decision)
        $this->assertEquals(90.0, $dailyReport->traveled_distance); // 100 + (-10)
        $this->assertEquals(3300, $dailyReport->work_duration); // 3600 + (-300)
        $this->assertEquals(540, $dailyReport->stoppage_duration); // 600 + (-60)
        $this->assertEquals(4, $dailyReport->stoppage_count); // 5 + (-1)
    }

    #[Test]
    public function it_handles_large_numbers()
    {
        $dailyReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today()->toDateString(),
            'traveled_distance' => 0,
            'work_duration' => 0,
            'stoppage_duration' => 0,
            'stoppage_count' => 0,
            'efficiency' => 0,
            'max_speed' => 0,
            'average_speed' => 0
        ]);

        $newData = [
            'totalTraveledDistance' => 999999.99,
            'totalMovingTime' => 86400, // 24 hours
            'totalStoppedTime' => 3600, // 1 hour
            'stoppageCount' => 1000,
            'maxSpeed' => 999
        ];

        $result = $this->service->update($dailyReport, $newData);

        $dailyReport->refresh();

        $this->assertEquals(999999.99, $dailyReport->traveled_distance);
        $this->assertEquals(86400, $dailyReport->work_duration);
        $this->assertEquals(3600, $dailyReport->stoppage_duration);
        $this->assertEquals(1000, $dailyReport->stoppage_count);
        $this->assertEquals(999, $dailyReport->max_speed);
    }

    // ===== NEW TESTS FOR DUAL RECORD FUNCTIONALITY =====

    #[Test]
    public function it_creates_daily_summary_record_with_null_task_id()
    {
        $dailyRecord = $this->service->fetchOrCreateDailyRecord();

        $this->assertInstanceOf(GpsMetricsCalculation::class, $dailyRecord);
        $this->assertEquals($this->tractor->id, $dailyRecord->tractor_id);
        $this->assertNull($dailyRecord->tractor_task_id);
        $this->assertEquals(today()->toDateString(), $dailyRecord->date);

        // Check default values
        $this->assertEquals(0, $dailyRecord->traveled_distance);
        $this->assertEquals(0, $dailyRecord->work_duration);
        $this->assertEquals(0, $dailyRecord->stoppage_duration);
        $this->assertEquals(0, $dailyRecord->stoppage_count);
        $this->assertEquals(0, $dailyRecord->efficiency);
        $this->assertEquals(0, $dailyRecord->max_speed);
        $this->assertEquals(0, $dailyRecord->average_speed);
    }

    #[Test]
    public function it_returns_existing_daily_summary_record()
    {
        // Create an existing daily summary record
        $existingRecord = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => null,
            'date' => today()->toDateString(),
            'traveled_distance' => 200.0,
            'work_duration' => 7200,
            'stoppage_duration' => 1200,
            'stoppage_count' => 8,
            'efficiency' => 25.0,
            'max_speed' => 30,
            'average_speed' => 25.0
        ]);

        $dailyRecord = $this->service->fetchOrCreateDailyRecord();

        $this->assertEquals($existingRecord->id, $dailyRecord->id);
        $this->assertEquals(200.0, $dailyRecord->traveled_distance);
        $this->assertEquals(7200, $dailyRecord->work_duration);
        $this->assertEquals(1200, $dailyRecord->stoppage_duration);
        $this->assertEquals(8, $dailyRecord->stoppage_count);
        $this->assertEquals(25.0, $dailyRecord->efficiency);
        $this->assertEquals(30, $dailyRecord->max_speed);
        $this->assertEquals(25.0, $dailyRecord->average_speed);
    }

    #[Test]
    public function it_updates_both_records_when_tractor_has_task()
    {
        // Create a farm, field, and operation
        $farm = Farm::factory()->create();
        $field = Field::factory()->create(['farm_id' => $farm->id]);
        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $service = new GpsMetricsCalculationService($this->tractor, $task);

        $processedData = [
            'totalTraveledDistance' => 15.5,
            'totalMovingTime' => 3600, // 1 hour
            'totalStoppedTime' => 1800, // 30 minutes
            'stoppageCount' => 3,
            'maxSpeed' => 25
        ];

        $records = $service->updateBothRecords($processedData);

        // Check task-specific record
        $this->assertNotNull($records['taskRecord']);
        $this->assertEquals($this->tractor->id, $records['taskRecord']->tractor_id);
        $this->assertEquals($task->id, $records['taskRecord']->tractor_task_id);
        $this->assertEquals(today()->toDateString(), $records['taskRecord']->date);
        $this->assertEquals(15.5, $records['taskRecord']->traveled_distance);
        $this->assertEquals(3600, $records['taskRecord']->work_duration);
        $this->assertEquals(1800, $records['taskRecord']->stoppage_duration);
        $this->assertEquals(3, $records['taskRecord']->stoppage_count);
        $this->assertEquals(25, $records['taskRecord']->max_speed);

        // Check daily summary record
        $this->assertNotNull($records['dailyRecord']);
        $this->assertEquals($this->tractor->id, $records['dailyRecord']->tractor_id);
        $this->assertNull($records['dailyRecord']->tractor_task_id);
        $this->assertEquals(today()->toDateString(), $records['dailyRecord']->date);
        $this->assertEquals(15.5, $records['dailyRecord']->traveled_distance);
        $this->assertEquals(3600, $records['dailyRecord']->work_duration);
        $this->assertEquals(1800, $records['dailyRecord']->stoppage_duration);
        $this->assertEquals(3, $records['dailyRecord']->stoppage_count);
        $this->assertEquals(25, $records['dailyRecord']->max_speed);
    }

    #[Test]
    public function it_updates_only_daily_record_when_tractor_has_no_task()
    {
        $processedData = [
            'totalTraveledDistance' => 25.0,
            'totalMovingTime' => 5400, // 1.5 hours
            'totalStoppedTime' => 900, // 15 minutes
            'stoppageCount' => 2,
            'maxSpeed' => 30
        ];

        $records = $this->service->updateBothRecords($processedData);

        // Check task-specific record (should be null)
        $this->assertNull($records['taskRecord']);

        // Check daily summary record
        $this->assertNotNull($records['dailyRecord']);
        $this->assertEquals($this->tractor->id, $records['dailyRecord']->tractor_id);
        $this->assertNull($records['dailyRecord']->tractor_task_id);
        $this->assertEquals(today()->toDateString(), $records['dailyRecord']->date);
        $this->assertEquals(25.0, $records['dailyRecord']->traveled_distance);
        $this->assertEquals(5400, $records['dailyRecord']->work_duration);
        $this->assertEquals(900, $records['dailyRecord']->stoppage_duration);
        $this->assertEquals(2, $records['dailyRecord']->stoppage_count);
        $this->assertEquals(30, $records['dailyRecord']->max_speed);
    }

    #[Test]
    public function it_handles_multiple_updates_for_both_records()
    {
        // Create a farm, field, and operation
        $farm = Farm::factory()->create();
        $field = Field::factory()->create(['farm_id' => $farm->id]);
        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $service = new GpsMetricsCalculationService($this->tractor, $task);

        // First update
        $firstData = [
            'totalTraveledDistance' => 10.0,
            'totalMovingTime' => 1800, // 30 minutes
            'totalStoppedTime' => 300, // 5 minutes
            'stoppageCount' => 1,
            'maxSpeed' => 15
        ];

        $firstRecords = $service->updateBothRecords($firstData);

        // Second update
        $secondData = [
            'totalTraveledDistance' => 20.0,
            'totalMovingTime' => 3600, // 1 hour
            'totalStoppedTime' => 600, // 10 minutes
            'stoppageCount' => 2,
            'maxSpeed' => 25
        ];

        $secondRecords = $service->updateBothRecords($secondData);

        // Refresh records to get updated values
        $firstRecords['taskRecord']->refresh();
        $firstRecords['dailyRecord']->refresh();
        $secondRecords['taskRecord']->refresh();
        $secondRecords['dailyRecord']->refresh();

        // Check task-specific record cumulative values
        $this->assertEquals(30.0, $secondRecords['taskRecord']->traveled_distance); // 10 + 20
        $this->assertEquals(5400, $secondRecords['taskRecord']->work_duration); // 1800 + 3600
        $this->assertEquals(900, $secondRecords['taskRecord']->stoppage_duration); // 300 + 600
        $this->assertEquals(3, $secondRecords['taskRecord']->stoppage_count); // 1 + 2
        $this->assertEquals(25, $secondRecords['taskRecord']->max_speed); // Latest max speed

        // Check daily summary record cumulative values
        $this->assertEquals(30.0, $secondRecords['dailyRecord']->traveled_distance); // 10 + 20
        $this->assertEquals(5400, $secondRecords['dailyRecord']->work_duration); // 1800 + 3600
        $this->assertEquals(900, $secondRecords['dailyRecord']->stoppage_duration); // 300 + 600
        $this->assertEquals(3, $secondRecords['dailyRecord']->stoppage_count); // 1 + 2
        $this->assertEquals(25, $secondRecords['dailyRecord']->max_speed); // Latest max speed
    }

    #[Test]
    public function it_creates_separate_records_for_different_tasks()
    {
        // Create a farm, field, and operation
        $farm = Farm::factory()->create();
        $field = Field::factory()->create(['farm_id' => $farm->id]);
        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create first task
        $task1 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        // Create second task
        $task2 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $service1 = new GpsMetricsCalculationService($this->tractor, $task1);
        $service2 = new GpsMetricsCalculationService($this->tractor, $task2);

        $data1 = [
            'totalTraveledDistance' => 10.0,
            'totalMovingTime' => 1800,
            'totalStoppedTime' => 300,
            'stoppageCount' => 1,
            'maxSpeed' => 15
        ];

        $data2 = [
            'totalTraveledDistance' => 20.0,
            'totalMovingTime' => 3600,
            'totalStoppedTime' => 600,
            'stoppageCount' => 2,
            'maxSpeed' => 25
        ];

        $records1 = $service1->updateBothRecords($data1);
        $records2 = $service2->updateBothRecords($data2);

        // Check that task-specific records are different
        $this->assertNotEquals($records1['taskRecord']->id, $records2['taskRecord']->id);
        $this->assertEquals($task1->id, $records1['taskRecord']->tractor_task_id);
        $this->assertEquals($task2->id, $records2['taskRecord']->tractor_task_id);

        // Check that daily records are the same (same tractor, same date, null task_id)
        $this->assertEquals($records1['dailyRecord']->id, $records2['dailyRecord']->id);
        $this->assertNull($records1['dailyRecord']->tractor_task_id);
        $this->assertNull($records2['dailyRecord']->tractor_task_id);

        // Check cumulative values in daily record
        $records2['dailyRecord']->refresh();
        $this->assertEquals(30.0, $records2['dailyRecord']->traveled_distance); // 10 + 20
        $this->assertEquals(5400, $records2['dailyRecord']->work_duration); // 1800 + 3600
        $this->assertEquals(900, $records2['dailyRecord']->stoppage_duration); // 300 + 600
        $this->assertEquals(3, $records2['dailyRecord']->stoppage_count); // 1 + 2
        $this->assertEquals(25, $records2['dailyRecord']->max_speed); // Latest max speed
    }

    #[Test]
    public function it_calculates_efficiency_correctly_for_both_records()
    {
        // Create a farm, field, and operation
        $farm = Farm::factory()->create();
        $field = Field::factory()->create(['farm_id' => $farm->id]);
        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $service = new GpsMetricsCalculationService($this->tractor, $task);

        $processedData = [
            'totalTraveledDistance' => 0,
            'totalMovingTime' => 14400, // 4 hours
            'totalStoppedTime' => 0,
            'stoppageCount' => 0,
            'maxSpeed' => 0
        ];

        $records = $service->updateBothRecords($processedData);

        // Efficiency = (moving_time / expected_daily_work_time) * 100
        // = (14400 / (8 * 3600)) * 100 = (14400 / 28800) * 100 = 50%

        // Check task-specific record efficiency
        $this->assertEquals(50.0, $records['taskRecord']->efficiency, '', 0.01);

        // Check daily summary record efficiency
        $this->assertEquals(50.0, $records['dailyRecord']->efficiency, '', 0.01);
    }

    #[Test]
    public function it_handles_zero_values_for_both_records()
    {
        // Create a farm, field, and operation
        $farm = Farm::factory()->create();
        $field = Field::factory()->create(['farm_id' => $farm->id]);
        $operation = Operation::factory()->create(['farm_id' => $farm->id]);

        // Create a task
        $task = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation->id,
            'taskable_type' => 'App\Models\Field',
            'taskable_id' => $field->id,
            'date' => today(),
            'status' => 'started'
        ]);

        $service = new GpsMetricsCalculationService($this->tractor, $task);

        $processedData = [
            'totalTraveledDistance' => 0,
            'totalMovingTime' => 0,
            'totalStoppedTime' => 0,
            'stoppageCount' => 0,
            'maxSpeed' => 0
        ];

        $records = $service->updateBothRecords($processedData);

        // Check task-specific record
        $this->assertEquals(0, $records['taskRecord']->traveled_distance);
        $this->assertEquals(0, $records['taskRecord']->work_duration);
        $this->assertEquals(0, $records['taskRecord']->stoppage_duration);
        $this->assertEquals(0, $records['taskRecord']->stoppage_count);
        $this->assertEquals(0, $records['taskRecord']->max_speed);
        $this->assertEquals(0, $records['taskRecord']->average_speed);

        // Check daily summary record
        $this->assertEquals(0, $records['dailyRecord']->traveled_distance);
        $this->assertEquals(0, $records['dailyRecord']->work_duration);
        $this->assertEquals(0, $records['dailyRecord']->stoppage_duration);
        $this->assertEquals(0, $records['dailyRecord']->stoppage_count);
        $this->assertEquals(0, $records['dailyRecord']->max_speed);
        $this->assertEquals(0, $records['dailyRecord']->average_speed);
    }
}
