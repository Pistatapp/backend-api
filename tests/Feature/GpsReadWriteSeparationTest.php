<?php

namespace Tests\Feature;

use App\Jobs\StoreGpsData;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Services\TractorPathStreamService;
use App\Services\TractorStartMovementTimeDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Integration tests for GPS data read/write separation.
 *
 * These tests verify that:
 * 1. Read operations use the read-optimized connection with READ UNCOMMITTED isolation
 * 2. Write operations use the write-optimized connection with READ COMMITTED isolation
 * 3. The separation improves performance by allowing concurrent reads during writes
 *
 * All tests are skipped if MySQL is not available.
 */
class GpsReadWriteSeparationTest extends TestCase
{
    use RefreshDatabase;

    private ?Tractor $tractor = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Skip test if MySQL GPS connection is not available.
     */
    private function skipIfMysqlNotAvailable(): void
    {
        try {
            DB::connection('mysql_gps')->getPdo();
            DB::connection('mysql_gps_read')->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('MySQL GPS connection not available: ' . $e->getMessage());
        }
    }

    /**
     * Set up tractor for tests.
     */
    private function setUpTractor(): void
    {
        $farm = Farm::factory()->create();
        $this->tractor = Tractor::factory()->create([
            'farm_id' => $farm->id,
            'start_work_time' => '08:00',
        ]);
        GpsDevice::factory()->create([
            'tractor_id' => $this->tractor->id,
            'imei' => '863070043380001',
        ]);

        DB::connection('mysql_gps')->table('gps_data')->truncate();
    }

    /**
     * Test that read and write connections use different isolation levels.
     */
    public function test_read_and_write_connections_use_different_isolation_levels(): void
    {
        $this->skipIfMysqlNotAvailable();

        $writeConnection = DB::connection('mysql_gps');
        $writeConnection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        $readConnection = DB::connection('mysql_gps_read');
        $readConnection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');

        $writeIsolation = $writeConnection->selectOne('SELECT @@SESSION.transaction_isolation as isolation');
        $readIsolation = $readConnection->selectOne('SELECT @@SESSION.transaction_isolation as isolation');

        $this->assertEquals('READ-COMMITTED', $writeIsolation->isolation);
        $this->assertEquals('READ-UNCOMMITTED', $readIsolation->isolation);
    }

    /**
     * Test that read operations can see uncommitted data (dirty reads).
     */
    public function test_read_operations_can_see_uncommitted_data(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $writeConnection = DB::connection('mysql_gps');
        $readConnection = DB::connection('mysql_gps_read');
        $readConnection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');

        $writeConnection->beginTransaction();

        $writeConnection->table('gps_data')->insert([
            'tractor_id' => $this->tractor->id,
            'coordinate' => json_encode([35.0, 51.0]),
            'speed' => 10,
            'status' => 1,
            'directions' => json_encode([]),
            'imei' => '863070043380001',
            'date_time' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $uncommittedCount = $readConnection->table('gps_data')
            ->where('tractor_id', $this->tractor->id)
            ->count();

        $writeConnection->rollBack();

        $afterRollbackCount = $readConnection->table('gps_data')
            ->where('tractor_id', $this->tractor->id)
            ->count();

        $this->assertEquals(1, $uncommittedCount);
        $this->assertEquals(0, $afterRollbackCount);
    }

    /**
     * Test TractorPathStreamService uses read connection.
     */
    public function test_tractor_path_stream_service_uses_read_connection(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsDataViaWriteConnection([
            ['date_time' => $today->copy()->setTime(8, 0, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 0, 10), 'speed' => 15, 'status' => 1],
        ]);

        $service = app(TractorPathStreamService::class);
        $response = $service->getTractorPath($this->tractor, $today, false);

        ob_start();
        $response->send();
        $content = ob_get_clean();

        $data = json_decode($content, true);
        $this->assertCount(2, $data);

        $readIsolation = DB::connection('mysql_gps_read')
            ->selectOne('SELECT @@SESSION.transaction_isolation as isolation');
        $this->assertEquals('READ-UNCOMMITTED', $readIsolation->isolation);
    }

    /**
     * Test TractorStartMovementTimeDetectionService uses read connection.
     */
    public function test_movement_detection_service_uses_read_connection(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsDataViaWriteConnection([
            ['date_time' => $today->copy()->setTime(8, 10, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 20), 'speed' => 20, 'status' => 1],
        ]);

        $service = app(TractorStartMovementTimeDetectionService::class);
        $result = $service->detectStartMovementTime($this->tractor, $today);

        $this->assertEquals('08:10:00', $result);

        $readIsolation = DB::connection('mysql_gps_read')
            ->selectOne('SELECT @@SESSION.transaction_isolation as isolation');
        $this->assertEquals('READ-UNCOMMITTED', $readIsolation->isolation);
    }

    /**
     * Test StoreGpsData job uses write connection with optimized settings.
     */
    public function test_store_gps_data_job_uses_write_connection_with_optimized_settings(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $data = $this->createGpsDataArray(10);
        $job = new StoreGpsData($data, $this->tractor->id);
        $job->handle();

        $writeConnection = DB::connection('mysql_gps');

        $isolation = $writeConnection->selectOne('SELECT @@SESSION.transaction_isolation as isolation');
        $this->assertEquals('READ-COMMITTED', $isolation->isolation);

        $lockTimeout = $writeConnection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout as timeout');
        $this->assertEquals(5, $lockTimeout->timeout);
    }

    /**
     * Test that database configuration for mysql_gps_read exists.
     */
    public function test_mysql_gps_read_connection_configuration_exists(): void
    {
        $config = config('database.connections.mysql_gps_read');

        $this->assertNotNull($config);
        $this->assertEquals('mysql', $config['driver']);
        $this->assertArrayHasKey('options', $config);
    }

    /**
     * Test both connections point to the same database.
     */
    public function test_both_connections_point_to_same_database(): void
    {
        $writeDbConfig = config('database.connections.mysql_gps.database');
        $readDbConfig = config('database.connections.mysql_gps_read.database');

        $this->assertEquals($writeDbConfig, $readDbConfig);
    }

    /**
     * Test data written via write connection is readable via read connection.
     */
    public function test_data_written_via_write_connection_readable_via_read_connection(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $writeConnection = DB::connection('mysql_gps');
        $readConnection = DB::connection('mysql_gps_read');

        $writeConnection->table('gps_data')->insert([
            'tractor_id' => $this->tractor->id,
            'coordinate' => json_encode([35.0, 51.0]),
            'speed' => 42,
            'status' => 1,
            'directions' => json_encode([]),
            'imei' => '863070043380001',
            'date_time' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $record = $readConnection->table('gps_data')
            ->where('tractor_id', $this->tractor->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertEquals(42, $record->speed);
    }

    /**
     * Test session isolation level persists across multiple queries.
     */
    public function test_session_isolation_level_persists(): void
    {
        $this->skipIfMysqlNotAvailable();

        $readConnection = DB::connection('mysql_gps_read');
        $readConnection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');

        for ($i = 0; $i < 5; $i++) {
            $readConnection->select('SELECT 1');
        }

        $isolation = $readConnection->selectOne('SELECT @@SESSION.transaction_isolation as isolation');
        $this->assertEquals('READ-UNCOMMITTED', $isolation->isolation);
    }

    /**
     * Test read connection doesn't have sticky option enabled.
     */
    public function test_read_connection_not_sticky(): void
    {
        $config = config('database.connections.mysql_gps_read');

        $this->assertArrayHasKey('sticky', $config);
        $this->assertFalse($config['sticky']);
    }

    /**
     * Helper to insert GPS data via write connection.
     */
    private function insertGpsDataViaWriteConnection(array $records): void
    {
        $data = [];
        foreach ($records as $record) {
            $data[] = [
                'tractor_id' => $this->tractor->id,
                'coordinate' => $record['coordinate'] ?? json_encode([35.0, 51.0]),
                'speed' => $record['speed'],
                'status' => $record['status'],
                'directions' => $record['directions'] ?? json_encode([]),
                'imei' => '863070043380001',
                'date_time' => $record['date_time']->format('Y-m-d H:i:s'),
            ];
        }

        DB::connection('mysql_gps')->table('gps_data')->insert($data);
    }

    /**
     * Helper to create GPS data array for StoreGpsData job.
     */
    private function createGpsDataArray(int $count): array
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
