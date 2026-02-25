<?php

namespace Tests\Unit\Services;

use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Services\TractorStartMovementTimeDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for TractorStartMovementTimeDetectionService.
 *
 * Tests requiring MySQL are skipped when the database is not available.
 */
class TractorStartMovementTimeDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TractorStartMovementTimeDetectionService $service;
    private ?Tractor $tractor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TractorStartMovementTimeDetectionService::class);
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
     * Set up tractor for tests that need it.
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
    }

    /**
     * Test returns null when no GPS data exists.
     */
    public function test_returns_null_when_no_gps_data(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $result = $this->service->detectStartMovementTime($this->tractor, Carbon::today());

        $this->assertNull($result);
    }

    /**
     * Test detects start movement time after 3 consecutive movements.
     */
    public function test_detects_start_movement_time_after_three_consecutive_movements(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(8, 0, 0), 'speed' => 0, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 20), 'speed' => 20, 'status' => 1],
        ]);

        $result = $this->service->detectStartMovementTime($this->tractor, $today);

        $this->assertEquals('08:10:00', $result);
    }

    /**
     * Test returns null when less than 3 consecutive movements.
     */
    public function test_returns_null_when_less_than_three_consecutive_movements(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(8, 10, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 20), 'speed' => 0, 'status' => 1],
        ]);

        $result = $this->service->detectStartMovementTime($this->tractor, $today);

        $this->assertNull($result);
    }

    /**
     * Test consecutive movement counter resets on stoppage.
     */
    public function test_consecutive_counter_resets_on_stoppage(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(8, 0, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 0, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 0, 20), 'speed' => 0, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 5, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 5, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 5, 20), 'speed' => 20, 'status' => 1],
        ]);

        $result = $this->service->detectStartMovementTime($this->tractor, $today);

        $this->assertEquals('08:05:00', $result);
    }

    /**
     * Test respects tractor work start time.
     */
    public function test_respects_tractor_work_start_time(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();
        $this->tractor->update(['start_work_time' => '09:00']);

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(8, 0, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 0, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 0, 20), 'speed' => 20, 'status' => 1],
            ['date_time' => $today->copy()->setTime(9, 30, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(9, 30, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(9, 30, 20), 'speed' => 20, 'status' => 1],
        ]);

        $result = $this->service->detectStartMovementTime($this->tractor, $today);

        $this->assertEquals('09:30:00', $result);
    }

    /**
     * Test movement requires both status=1 and speed>0.
     */
    public function test_movement_requires_status_one_and_speed_greater_than_zero(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(8, 0, 0), 'speed' => 10, 'status' => 0],
            ['date_time' => $today->copy()->setTime(8, 0, 10), 'speed' => 15, 'status' => 0],
            ['date_time' => $today->copy()->setTime(8, 0, 20), 'speed' => 20, 'status' => 0],
            ['date_time' => $today->copy()->setTime(8, 5, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 5, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 5, 20), 'speed' => 20, 'status' => 1],
        ]);

        $result = $this->service->detectStartMovementTime($this->tractor, $today);

        $this->assertEquals('08:05:00', $result);
    }

    /**
     * Test uses read-optimized connection with READ UNCOMMITTED.
     */
    public function test_uses_read_optimized_connection(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(8, 10, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 20), 'speed' => 20, 'status' => 1],
        ]);

        $this->service->detectStartMovementTime($this->tractor, $today);

        $isolation = DB::connection('mysql_gps_read')
            ->selectOne('SELECT @@SESSION.transaction_isolation as isolation');

        $this->assertEquals('READ-UNCOMMITTED', $isolation->isolation);
    }

    /**
     * Test caches result after first detection.
     */
    public function test_caches_result_after_first_detection(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(8, 10, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 10, 20), 'speed' => 20, 'status' => 1],
        ]);

        $result1 = $this->service->detectStartMovementTime($this->tractor, $today);

        DB::connection('mysql_gps')->table('gps_data')->truncate();

        $result2 = $this->service->detectStartMovementTime($this->tractor, $today);

        $this->assertEquals($result1, $result2);
    }

    /**
     * Test returns correct time format.
     */
    public function test_returns_correct_time_format(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(14, 35, 22), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(14, 35, 32), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(14, 35, 42), 'speed' => 20, 'status' => 1],
        ]);

        $result = $this->service->detectStartMovementTime($this->tractor, $today);

        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $result);
        $this->assertEquals('14:35:22', $result);
    }

    /**
     * Helper method to insert GPS data for testing.
     */
    private function insertGpsData(int $tractorId, array $records): void
    {
        $data = [];
        foreach ($records as $record) {
            $data[] = [
                'tractor_id' => $tractorId,
                'coordinate' => json_encode([35.0, 51.0]),
                'speed' => $record['speed'],
                'status' => $record['status'],
                'directions' => json_encode([]),
                'imei' => '863070043380001',
                'date_time' => $record['date_time']->format('Y-m-d H:i:s'),
            ];
        }

        DB::connection('mysql_gps')->table('gps_data')->insert($data);
    }
}
