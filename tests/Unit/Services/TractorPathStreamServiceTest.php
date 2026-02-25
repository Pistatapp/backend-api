<?php

namespace Tests\Unit\Services;

use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Services\TractorPathStreamService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for TractorPathStreamService.
 *
 * Tests requiring MySQL are skipped when the database is not available.
 */
class TractorPathStreamServiceTest extends TestCase
{
    use RefreshDatabase;

    private TractorPathStreamService $service;
    private ?Tractor $tractor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TractorPathStreamService::class);
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
        $this->tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $this->tractor->id,
            'imei' => '863070043380001',
        ]);
    }

    /**
     * Test service returns empty iterator when no GPS data exists.
     */
    public function test_returns_empty_iterator_when_no_gps_data(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $response = $this->service->getTractorPath($this->tractor, Carbon::today());

        ob_start();
        $response->send();
        $content = ob_get_clean();

        $this->assertEquals('[]', $content);
    }

    /**
     * Test service returns GPS data for the specified date.
     */
    public function test_returns_gps_data_for_specified_date(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(8, 0, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 0, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 0, 20), 'speed' => 20, 'status' => 1],
        ]);

        $response = $this->service->getTractorPath($this->tractor, $today);

        ob_start();
        $response->send();
        $content = ob_get_clean();

        $data = json_decode($content, true);

        $this->assertIsArray($data);
        $this->assertCount(3, $data);
    }

    /**
     * Test service uses read-optimized connection with READ UNCOMMITTED.
     */
    public function test_uses_read_optimized_connection(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(8, 0, 0), 'speed' => 10, 'status' => 1],
        ]);

        $response = $this->service->getTractorPath($this->tractor, $today);
        ob_start();
        $response->send();
        ob_get_clean();

        $isolationAfter = DB::connection('mysql_gps_read')
            ->selectOne('SELECT @@SESSION.transaction_isolation as isolation');

        $this->assertEquals('READ-UNCOMMITTED', $isolationAfter->isolation);
    }

    /**
     * Test service returns last point from previous date when no data for current date.
     */
    public function test_returns_last_point_from_previous_date(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $yesterday = $today->copy()->subDay();

        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $yesterday->copy()->setTime(23, 59, 50), 'speed' => 5, 'status' => 1],
        ]);

        $response = $this->service->getTractorPath($this->tractor, $today);

        ob_start();
        $response->send();
        $content = ob_get_clean();

        $data = json_decode($content, true);

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertEquals(5, $data[0]['speed']);
    }

    /**
     * Test service excludes data from other dates.
     */
    public function test_excludes_data_from_other_dates(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $yesterday = $today->copy()->subDay();
        $tomorrow = $today->copy()->addDay();

        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $yesterday->copy()->setTime(12, 0, 0), 'speed' => 5, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 0, 0), 'speed' => 10, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 0, 10), 'speed' => 15, 'status' => 1],
            ['date_time' => $tomorrow->copy()->setTime(8, 0, 0), 'speed' => 20, 'status' => 1],
        ]);

        $response = $this->service->getTractorPath($this->tractor, $today);

        ob_start();
        $response->send();
        $content = ob_get_clean();

        $data = json_decode($content, true);

        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $this->assertEquals(10, $data[0]['speed']);
        $this->assertEquals(15, $data[1]['speed']);
    }

    /**
     * Test service formats response correctly.
     */
    public function test_formats_response_correctly(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $today = Carbon::today();
        $this->insertGpsData($this->tractor->id, [
            [
                'date_time' => $today->copy()->setTime(8, 30, 45),
                'coordinate' => json_encode([35.123, 51.456]),
                'speed' => 25,
                'status' => 1,
                'directions' => json_encode(['heading' => 90, 'bearing' => 180]),
            ],
        ]);

        $response = $this->service->getTractorPath($this->tractor, $today);

        ob_start();
        $response->send();
        $content = ob_get_clean();

        $data = json_decode($content, true);
        $point = $data[0];

        $this->assertArrayHasKey('id', $point);
        $this->assertArrayHasKey('latitude', $point);
        $this->assertArrayHasKey('longitude', $point);
        $this->assertArrayHasKey('speed', $point);
        $this->assertArrayHasKey('status', $point);
        $this->assertArrayHasKey('is_starting_point', $point);
        $this->assertArrayHasKey('is_ending_point', $point);
        $this->assertArrayHasKey('is_stopped', $point);
        $this->assertArrayHasKey('directions', $point);
        $this->assertArrayHasKey('stoppage_time', $point);
        $this->assertArrayHasKey('timestamp', $point);

        $this->assertEquals(35.123, $point['latitude']);
        $this->assertEquals(51.456, $point['longitude']);
        $this->assertEquals(25, $point['speed']);
        $this->assertEquals('08:30:45', $point['timestamp']);
    }

    /**
     * Test service excludes data from other tractors.
     */
    public function test_excludes_data_from_other_tractors(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->setUpTractor();

        $otherTractor = Tractor::factory()->create(['farm_id' => $this->tractor->farm_id]);
        $today = Carbon::today();

        $this->insertGpsData($this->tractor->id, [
            ['date_time' => $today->copy()->setTime(8, 0, 0), 'speed' => 10, 'status' => 1],
        ]);

        $this->insertGpsData($otherTractor->id, [
            ['date_time' => $today->copy()->setTime(8, 0, 0), 'speed' => 20, 'status' => 1],
            ['date_time' => $today->copy()->setTime(8, 0, 10), 'speed' => 25, 'status' => 1],
        ]);

        $response = $this->service->getTractorPath($this->tractor, $today, false);

        ob_start();
        $response->send();
        $content = ob_get_clean();

        $data = json_decode($content, true);

        $this->assertCount(1, $data);
        $this->assertEquals(10, $data[0]['speed']);
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
}
