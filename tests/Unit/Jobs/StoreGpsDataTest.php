<?php

namespace Tests\Unit\Jobs;

use App\Jobs\StoreGpsData;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for StoreGpsData job.
 *
 * Tests requiring MySQL are skipped when the database is not available.
 */
class StoreGpsDataTest extends TestCase
{
    use RefreshDatabase;

    private ?Tractor $tractor = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Skip test if MySQL GPS connection is not available.
     */
    private function skipIfMysqlNotAvailable(): void
    {
        try {
            DB::connection('mysql_gps')->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('MySQL GPS connection not available: ' . $e->getMessage());
        }
    }

    /**
     * Set up tractor for tests that need it.
     */
    private function setUpTractor(): void
    {
        $farm = Farm::factory()->create();
        $this->tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $this->tractor->id,
            'imei' => '863070043380001',
        ]);

        try {
            DB::connection('mysql_gps')->table('gps_data')->truncate();
        } catch (\Exception $e) {
            // Ignore if table doesn't exist in SQLite
        }
    }

    /**
     * Test job is queued on gps-storage queue.
     */
    public function test_job_is_queued_on_gps_storage_queue(): void
    {
        Queue::fake();

        $data = $this->createGpsData(1);
        StoreGpsData::dispatch($data, 1);

        Queue::assertPushedOn('gps-storage', StoreGpsData::class);
    }

    /**
     * Test job stores single GPS record.
     */
    public function test_stores_single_gps_record(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $data = $this->createGpsData(1);
        $job = new StoreGpsData($data, $this->tractor->id);
        $job->handle();

        $count = DB::connection('mysql_gps')->table('gps_data')->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Test job stores multiple GPS records.
     */
    public function test_stores_multiple_gps_records(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $data = $this->createGpsData(50);
        $job = new StoreGpsData($data, $this->tractor->id);
        $job->handle();

        $count = DB::connection('mysql_gps')->table('gps_data')->count();
        $this->assertEquals(50, $count);
    }

    /**
     * Test job handles large batches correctly.
     */
    public function test_handles_large_batches_correctly(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $data = $this->createGpsData(600);
        $job = new StoreGpsData($data, $this->tractor->id);
        $job->handle();

        $count = DB::connection('mysql_gps')->table('gps_data')->count();
        $this->assertEquals(600, $count);
    }

    /**
     * Test job skips empty data.
     */
    public function test_skips_empty_data(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $job = new StoreGpsData([], $this->tractor->id);
        $job->handle();

        $count = DB::connection('mysql_gps')->table('gps_data')->count();
        $this->assertEquals(0, $count);
    }

    /**
     * Test job stores all required fields correctly.
     */
    public function test_stores_all_required_fields_correctly(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $dateTime = Carbon::now()->format('Y-m-d H:i:s');
        $data = [
            [
                'coordinate' => [35.123, 51.456],
                'speed' => 25,
                'status' => 1,
                'directions' => ['heading' => 90, 'bearing' => 180],
                'imei' => '863070043380001',
                'date_time' => $dateTime,
            ],
        ];

        $job = new StoreGpsData($data, $this->tractor->id);
        $job->handle();

        $record = DB::connection('mysql_gps')->table('gps_data')->first();

        $this->assertEquals($this->tractor->id, $record->tractor_id);
        $this->assertEquals(json_encode([35.123, 51.456]), $record->coordinate);
        $this->assertEquals(25, $record->speed);
        $this->assertEquals(1, $record->status);
        $this->assertEquals(json_encode(['heading' => 90, 'bearing' => 180]), $record->directions);
        $this->assertEquals('863070043380001', $record->imei);
        $this->assertEquals($dateTime, $record->date_time);
    }

    /**
     * Test job sets optimized session variables (READ COMMITTED isolation).
     */
    public function test_sets_optimized_session_variables(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $data = $this->createGpsData(5);
        $job = new StoreGpsData($data, $this->tractor->id);
        $job->handle();

        $connection = DB::connection('mysql_gps');

        $isolation = $connection->selectOne('SELECT @@SESSION.transaction_isolation as isolation');
        $this->assertEquals('READ-COMMITTED', $isolation->isolation);

        $lockTimeout = $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout as timeout');
        $this->assertEquals(5, $lockTimeout->timeout);
    }

    /**
     * Test job uses batch size of 500.
     */
    public function test_uses_batch_size_of_500(): void
    {
        $job = new StoreGpsData([], 1);

        $reflection = new \ReflectionClass($job);
        $constant = $reflection->getConstant('BATCH_SIZE');

        $this->assertEquals(500, $constant);
    }

    /**
     * Test job has retry configuration.
     */
    public function test_has_retry_configuration(): void
    {
        $job = new StoreGpsData([], 1);

        $this->assertEquals(5, $job->tries);
        $this->assertEquals(3, $job->maxExceptions);
        $this->assertEquals(60, $job->timeout);
        $this->assertEquals([1, 3, 5, 10, 20], $job->backoff);
    }

    /**
     * Test job correctly serializes coordinate as JSON.
     */
    public function test_serializes_coordinate_as_json(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $data = [
            [
                'coordinate' => [35.123456, 51.789012],
                'speed' => 10,
                'status' => 1,
                'directions' => [],
                'imei' => '863070043380001',
                'date_time' => Carbon::now()->format('Y-m-d H:i:s'),
            ],
        ];

        $job = new StoreGpsData($data, $this->tractor->id);
        $job->handle();

        $record = DB::connection('mysql_gps')->table('gps_data')->first();
        $coordinate = json_decode($record->coordinate, true);

        $this->assertIsArray($coordinate);
        $this->assertEquals(35.123456, $coordinate[0]);
        $this->assertEquals(51.789012, $coordinate[1]);
    }

    /**
     * Test job assigns correct tractor_id to all records.
     */
    public function test_assigns_correct_tractor_id_to_all_records(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $data = $this->createGpsData(50);
        $job = new StoreGpsData($data, $this->tractor->id);
        $job->handle();

        $wrongTractorCount = DB::connection('mysql_gps')
            ->table('gps_data')
            ->where('tractor_id', '!=', $this->tractor->id)
            ->count();

        $this->assertEquals(0, $wrongTractorCount);
    }

    /**
     * Test job stores sample payload format correctly (coordinate, directions, date_time, imei).
     */
    public function test_stores_sample_payload_format_correctly(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $data = [
            [
                'coordinate' => [35.937893, 50.065403],
                'speed' => 0,
                'status' => 0,
                'directions' => ['ew' => 3, 'ns' => 1],
                'date_time' => '2026-02-25 18:49:45',
                'imei' => '863070046120282',
            ],
            [
                'coordinate' => [35.969272, 50.120115],
                'speed' => 0,
                'status' => 0,
                'directions' => ['ew' => 3, 'ns' => 1],
                'date_time' => '2026-02-25 18:42:58',
                'imei' => '863070043373009',
            ],
        ];

        $job = new StoreGpsData($data, $this->tractor->id);
        $job->handle();

        $count = DB::connection('mysql_gps')->table('gps_data')->count();
        $this->assertEquals(2, $count);

        $records = DB::connection('mysql_gps')->table('gps_data')
            ->orderBy('date_time')
            ->get();

        $this->assertCount(2, $records);
        $first = $records[0];
        $this->assertEquals($this->tractor->id, $first->tractor_id);
        $this->assertEquals('2026-02-25 18:42:58', $first->date_time);
        $this->assertEquals(0, (int) $first->speed);
        $this->assertEquals(0, (int) $first->status);
        $this->assertEquals('863070043373009', $first->imei);
        $coordinate = json_decode($first->coordinate, true);
        $this->assertIsArray($coordinate);
        $this->assertEqualsWithDelta(35.969272, $coordinate[0], 0.0001);
        $this->assertEqualsWithDelta(50.120115, $coordinate[1], 0.0001);
        $directions = json_decode($first->directions, true);
        $this->assertEquals(['ew' => 3, 'ns' => 1], $directions);

        $second = $records[1];
        $this->assertEquals($this->tractor->id, $second->tractor_id);
        $this->assertEquals('2026-02-25 18:49:45', $second->date_time);
        $this->assertEquals('863070046120282', $second->imei);
        $coordinate2 = json_decode($second->coordinate, true);
        $this->assertEqualsWithDelta(35.937893, $coordinate2[0], 0.0001);
        $this->assertEqualsWithDelta(50.065403, $coordinate2[1], 0.0001);
    }

    /**
     * Test failed method logs error correctly.
     */
    public function test_failed_method_logs_error(): void
    {
        $data = $this->createGpsData(5);
        $job = new StoreGpsData($data, 1);

        $exception = new \Exception('Test failure');

        Log::shouldReceive('error')
            ->once()
            ->with('StoreGpsData failed', \Mockery::on(function ($context) {
                return $context['tractor_id'] === 1
                    && isset($context['record']) && count($context['record']) === 5
                    && $context['error'] === 'Test failure';
            }));

        $job->failed($exception);
    }

    /**
     * Helper to create GPS data for testing.
     */
    private function createGpsData(int $count): array
    {
        $data = [];
        $baseTime = Carbon::now();

        for ($i = 0; $i < $count; $i++) {
            $data[] = [
                'coordinate' => [35.0 + ($i * 0.001), 51.0 + ($i * 0.001)],
                'speed' => rand(0, 50),
                'status' => rand(0, 1),
                'directions' => ['heading' => rand(0, 360)],
                'imei' => '863070043380001',
                'date_time' => $baseTime->copy()->addSeconds($i)->format('Y-m-d H:i:s'),
            ];
        }

        return $data;
    }
}
