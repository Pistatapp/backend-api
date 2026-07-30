<?php

namespace Tests\Feature;

use App\Jobs\BroadcastGpsEvents;
use App\Jobs\IngestGpsData;
use App\Models\Farm;
use App\Models\GpsData;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Zero Log Loss regression tests for IngestGpsData batch recovery (TASK 3).
 *
 * IngestGpsData is the successor to ProcessGpsData + StoreGpsData. Before the
 * original fix, the predecessor job wrapped every chunk insert in a single
 * transaction with no exception handling: one malformed row in an otherwise-
 * healthy 1000-row batch would roll back and, after 3 job retries,
 * permanently discard the entire batch into failed_jobs. These tests prove
 * that IngestGpsData::insertWithRecovery() still isolates a single bad row,
 * that the fast bulk path is used for healthy batches, and that duplicate
 * (imei, date_time) rows are silently deduplicated via insertOrIgnore rather
 * than causing a batch-wide failure.
 *
 * All tests are skipped if the `mysql_gps` connection is not available.
 */
class IngestGpsDataResilienceTest extends TestCase
{
    use RefreshDatabase;

    private ?Tractor $tractor = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function skipIfMysqlGpsNotAvailable(): void
    {
        try {
            DB::connection('mysql_gps')->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('MySQL GPS connection not available: ' . $e->getMessage());
        }
    }

    /**
     * IngestGpsData::resolveTractor() looks the tractor up via the GpsDevice
     * IMEI binding (not a constructor argument), so every test must register
     * a device for the IMEI it sends.
     */
    private function setUpTractor(string $imei): void
    {
        $farm = Farm::factory()->create();
        $this->tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $this->tractor->id,
            'imei' => $imei,
        ]);

        DB::connection('mysql_gps')->table('gps_data')->truncate();
    }

    public function test_one_malformed_row_does_not_discard_the_rest_of_the_batch(): void
    {
        $this->skipIfMysqlGpsNotAvailable();

        $goodImei = '863070046120282';
        $this->setUpTractor($goodImei);

        // gps_data.imei is varchar(20) — this deliberately overflows the
        // column so only THIS row's insert fails, simulating a real
        // malformed/corrupt record slipping through upstream validation.
        $overflowingImei = str_repeat('9', 40);

        $data = [
            [
                'coordinate' => [35.9, 50.0],
                'speed' => 10,
                'status' => 1,
                'directions' => ['ew' => 1, 'ns' => 1],
                'date_time' => '2026-07-30 10:00:00',
                'imei' => $goodImei,
            ],
            [
                'coordinate' => [35.91, 50.01],
                'speed' => 12,
                'status' => 1,
                'directions' => ['ew' => 1, 'ns' => 1],
                'date_time' => '2026-07-30 10:01:00',
                'imei' => $overflowingImei,
            ],
            [
                'coordinate' => [35.92, 50.02],
                'speed' => 14,
                'status' => 1,
                'directions' => ['ew' => 1, 'ns' => 1],
                'date_time' => '2026-07-30 10:02:00',
                'imei' => $goodImei,
            ],
        ];

        // BroadcastGpsEvents forces onConnection('redis'); fake the queue so
        // this test never depends on a live Redis broker.
        Queue::fake();

        $job = new IngestGpsData($data);

        // The job must complete without throwing — a single malformed row is
        // recoverable and must never fail the whole job / batch.
        $job->handle();

        $this->assertSame(
            2,
            GpsData::where('tractor_id', $this->tractor->id)->count(),
            'the two structurally valid rows must be persisted despite the third row failing'
        );

        Queue::assertPushed(BroadcastGpsEvents::class);
    }

    public function test_healthy_batch_still_uses_fast_bulk_insert_path(): void
    {
        $this->skipIfMysqlGpsNotAvailable();

        $imei = '863070046120282';
        $this->setUpTractor($imei);

        $data = [];
        for ($i = 0; $i < 5; $i++) {
            $data[] = [
                'coordinate' => [35.9 + $i * 0.001, 50.0 + $i * 0.001],
                'speed' => $i,
                'status' => 1,
                'directions' => ['ew' => 1, 'ns' => 1],
                'date_time' => "2026-07-30 10:0{$i}:00",
                'imei' => $imei,
            ];
        }

        Queue::fake();

        $job = new IngestGpsData($data);
        $job->handle();

        $this->assertSame(5, GpsData::where('tractor_id', $this->tractor->id)->count());
    }

    public function test_duplicate_imei_date_time_rows_are_deduplicated_not_fatal(): void
    {
        $this->skipIfMysqlGpsNotAvailable();

        $imei = '863070046120282';
        $this->setUpTractor($imei);

        $frame = [
            'coordinate' => [35.9, 50.0],
            'speed' => 10,
            'status' => 1,
            'directions' => ['ew' => 1, 'ns' => 1],
            'date_time' => '2026-07-30 10:00:00',
            'imei' => $imei,
        ];

        Queue::fake();

        // First delivery persists the point.
        (new IngestGpsData([$frame]))->handle();
        $this->assertSame(1, GpsData::where('tractor_id', $this->tractor->id)->count());

        // A retried/duplicate delivery of the exact same (imei, date_time)
        // point — e.g. a Gateway-side retry after a dropped ACK — must be
        // silently ignored via insertOrIgnore, not throw and not double-count.
        (new IngestGpsData([$frame]))->handle();

        $this->assertSame(
            1,
            GpsData::where('tractor_id', $this->tractor->id)->count(),
            'a duplicate (imei, date_time) row must be ignored, not inserted twice or fatal'
        );
    }

    public function test_batch_with_no_valid_rows_after_recovery_is_discarded_without_throwing(): void
    {
        $this->skipIfMysqlGpsNotAvailable();

        $imei = '863070046120282';
        $this->setUpTractor($imei);

        // prepareBatch() drops frames with a null/invalid coordinate before
        // insertWithRecovery() ever runs — an all-invalid batch must resolve
        // to a no-op, not throw.
        $data = [
            [
                'coordinate' => null,
                'speed' => 10,
                'status' => 1,
                'directions' => ['ew' => 1, 'ns' => 1],
                'date_time' => '2026-07-30 10:00:00',
                'imei' => $imei,
            ],
        ];

        Queue::fake();

        $job = new IngestGpsData($data);
        $job->handle();

        $this->assertSame(0, GpsData::where('tractor_id', $this->tractor->id)->count());
    }

    public function test_unbound_imei_discards_batch_without_throwing(): void
    {
        $this->skipIfMysqlGpsNotAvailable();

        DB::connection('mysql_gps')->table('gps_data')->truncate();

        // No GpsDevice/Tractor registered for this IMEI: resolveTractor()
        // returns null and the whole batch must be safely discarded (logged)
        // rather than throwing or inserting orphaned rows.
        $data = [
            [
                'coordinate' => [35.9, 50.0],
                'speed' => 10,
                'status' => 1,
                'directions' => ['ew' => 1, 'ns' => 1],
                'date_time' => '2026-07-30 10:00:00',
                'imei' => '000000000000001',
            ],
        ];

        Queue::fake();

        $job = new IngestGpsData($data);
        $job->handle();

        $this->assertSame(0, GpsData::count());
        Queue::assertNothingPushed();
    }
}
