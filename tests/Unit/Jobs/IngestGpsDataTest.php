<?php

namespace Tests\Unit\Jobs;

use App\Jobs\BroadcastGpsEvents;
use App\Jobs\IngestGpsData;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestGpsDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Sample data matching the GPS report payload format.
     */
    private function sampleData(): array
    {
        return [
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
    }

    public function test_job_is_queued_on_gps_processing_queue(): void
    {
        Queue::fake();

        $data = $this->sampleData();
        IngestGpsData::dispatch($data);

        Queue::assertPushedOn('gps-processing', IngestGpsData::class);
    }

    private function skipIfMysqlGpsNotAvailable(): void
    {
        $connection = config('database.connections.mysql_gps');

        if (($connection['database'] ?? null) === ':memory:') {
            $this->markTestSkipped('MySQL GPS connection not available in this environment.');
        }

        try {
            DB::connection('mysql_gps')->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('MySQL GPS connection not available: ' . $e->getMessage());
        }
    }

    public function test_dispatches_broadcast_gps_events_when_tractor_found(): void
    {
        $this->skipIfMysqlGpsNotAvailable();
        Queue::fake();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);

        $data = $this->sampleData();
        $job = new IngestGpsData($data);
        $job->handle();

        Queue::assertPushed(BroadcastGpsEvents::class, function (BroadcastGpsEvents $broadcastJob) use ($tractor, $data) {
            $this->assertEquals($data, $broadcastJob->data);
            $this->assertSame($tractor->id, $broadcastJob->tractorId);
            $this->assertSame('863070046120282', $broadcastJob->deviceImei);

            return true;
        });
    }

    public function test_does_not_dispatch_broadcast_when_no_tractor_for_imei(): void
    {
        Queue::fake();
        Log::fake();

        $data = $this->sampleData();
        $job = new IngestGpsData($data);
        $job->handle();

        Queue::assertNotPushed(BroadcastGpsEvents::class);

        Log::assertLogged(function ($log) {
            return $log->level === 'warning'
                && str_contains($log->message, 'unbound IMEI')
                && ($log->context['imei'] ?? null) === '863070046120282';
        });
    }

    public function test_overlapping_middleware_releases_instead_of_discarding(): void
    {
        $job = new IngestGpsData($this->sampleData());
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);

        $reflection = new \ReflectionObject($middleware[0]);
        $dontRelease = $reflection->getProperty('dontRelease');
        $dontRelease->setAccessible(true);
        $this->assertFalse($dontRelease->getValue($middleware[0]));

        $releaseAfter = $reflection->getProperty('releaseAfter');
        $releaseAfter->setAccessible(true);
        $this->assertSame(3, $releaseAfter->getValue($middleware[0]));
    }

    public function test_uses_first_item_imei_to_resolve_tractor(): void
    {
        $this->skipIfMysqlGpsNotAvailable();
        Queue::fake();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);

        $data = $this->sampleData();
        $job = new IngestGpsData($data);
        $job->handle();

        Queue::assertPushed(BroadcastGpsEvents::class, function (BroadcastGpsEvents $broadcastJob) use ($tractor) {
            return $broadcastJob->tractorId === $tractor->id && count($broadcastJob->data) === 2;
        });
    }

    public function test_duplicate_records_are_ignored_on_insert(): void
    {
        Queue::fake();

        $this->skipIfMysqlGpsNotAvailable();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);

        $data = [$this->sampleData()[0]];
        $job = new IngestGpsData($data);

        $job->handle();
        $job->handle();

        $count = DB::connection('mysql_gps')
            ->table('gps_data')
            ->where('imei', '863070046120282')
            ->where('date_time', '2026-02-25 18:49:45')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_prepare_batch_skips_frames_with_missing_coordinate(): void
    {
        $this->skipIfMysqlGpsNotAvailable();
        Queue::fake();
        Log::fake();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);

        $good = $this->sampleData()[0];
        $bad = $good;
        unset($bad['coordinate']);

        $job = new IngestGpsData([$good, $bad]);
        $job->handle();

        $count = DB::connection('mysql_gps')
            ->table('gps_data')
            ->where('imei', '863070046120282')
            ->where('date_time', '2026-02-25 18:49:45')
            ->count();

        $this->assertSame(1, $count);
        Log::assertLogged(fn ($log) => $log->level === 'warning' && str_contains($log->message, 'missing field'));
    }

    public function test_colliding_date_times_are_ordered_by_spatial_progression_then_staggered(): void
    {
        $this->skipIfMysqlGpsNotAvailable();
        Queue::fake();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);

        $base = $this->sampleData()[0];
        // Packet order is A, C, B (C is farther than B). Spatial chain must be A→B→C.
        $batch = [
            array_merge($base, [
                'date_time' => '2026-02-25 18:49:45',
                'coordinate' => [35.000000, 50.000000], // A
                'speed' => 10,
                'status' => 0,
            ]),
            array_merge($base, [
                'date_time' => '2026-02-25 18:49:45',
                'coordinate' => [35.000200, 50.000000], // C (farther along lat)
                'speed' => 14,
                'status' => 1,
            ]),
            array_merge($base, [
                'date_time' => '2026-02-25 18:49:45',
                'coordinate' => [35.000100, 50.000000], // B (between A and C)
                'speed' => 12,
                'status' => 0,
            ]),
        ];

        (new IngestGpsData($batch))->handle();

        $rows = DB::connection('mysql_gps')
            ->table('gps_data')
            ->where('imei', '863070046120282')
            ->where('date_time', '>=', '2026-02-25 18:49:45')
            ->where('date_time', '<=', '2026-02-25 18:49:47')
            ->orderBy('date_time')
            ->get(['date_time', 'speed', 'coordinate']);

        $this->assertCount(3, $rows);
        $this->assertSame(
            ['2026-02-25 18:49:45', '2026-02-25 18:49:46', '2026-02-25 18:49:47'],
            $rows->pluck('date_time')->map(fn ($dt) => (string) $dt)->all()
        );
        // Speeds follow spatial order A(10) → B(12) → C(14), not packet order A,C,B.
        $this->assertSame([10, 12, 14], $rows->pluck('speed')->map(fn ($s) => (int) $s)->all());
    }
}
