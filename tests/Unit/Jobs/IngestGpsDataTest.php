<?php

namespace Tests\Unit\Jobs;

use App\Jobs\BroadcastGpsEvents;
use App\Jobs\IngestGpsData;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Carbon\Carbon;
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
        $t0 = Carbon::now()->subMinutes(2)->format('Y-m-d H:i:s');
        $t1 = Carbon::now()->subMinutes(1)->format('Y-m-d H:i:s');

        return [
            [
                'coordinate' => [35.937893, 50.065403],
                'speed' => 0,
                'status' => 0,
                'directions' => ['ew' => 3, 'ns' => 1],
                'date_time' => $t0,
                'imei' => '863070046120282',
            ],
            [
                'coordinate' => [35.969272, 50.120115],
                'speed' => 0,
                'status' => 0,
                'directions' => ['ew' => 3, 'ns' => 1],
                'date_time' => $t1,
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
        Log::spy();

        $data = $this->sampleData();
        $job = new IngestGpsData($data);
        $job->handle();

        Queue::assertNotPushed(BroadcastGpsEvents::class);

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return str_contains((string) $message, 'GPS item quarantined')
                && ($context['imei'] ?? null) === '863070046120282';
        });
    }

    public function test_overlapping_middleware_releases_instead_of_discarding(): void
    {
        $job = new IngestGpsData($this->sampleData());
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);

        $this->assertSame(3, $middleware[0]->releaseAfter);
        $this->assertGreaterThan(0, $middleware[0]->expiresAfter);
    }

    public function test_processes_each_imei_against_its_own_tractor(): void
    {
        $this->skipIfMysqlGpsNotAvailable();
        Queue::fake();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        $secondTractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);
        GpsDevice::factory()->create([
            'tractor_id' => $secondTractor->id,
            'imei' => '863070043373009',
        ]);

        $data = $this->sampleData();
        $job = new IngestGpsData($data);
        $job->handle();

        Queue::assertPushed(BroadcastGpsEvents::class, 2);
        Queue::assertPushed(BroadcastGpsEvents::class, function (BroadcastGpsEvents $broadcastJob) use ($tractor) {
            return $broadcastJob->tractorId === $tractor->id
                && count($broadcastJob->data) === 1
                && $broadcastJob->data[0]['imei'] === '863070046120282';
        });
        Queue::assertPushed(BroadcastGpsEvents::class, function (BroadcastGpsEvents $broadcastJob) use ($secondTractor) {
            return $broadcastJob->tractorId === $secondTractor->id
                && count($broadcastJob->data) === 1
                && $broadcastJob->data[0]['imei'] === '863070043373009';
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
            ->where('date_time', $data[0]['date_time'])
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_replay_keeps_a_second_event_when_coordinate_differs(): void
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
        (new IngestGpsData([$base]))->handle();

        $updated = array_merge($base, [
            'coordinate' => [35.999001, 50.999001],
            'speed' => 42,
        ]);
        (new IngestGpsData([$updated]))->handle();

        $rows = DB::connection('mysql_gps')
            ->table('gps_data')
            ->where('imei', '863070046120282')
            ->where('date_time', $base['date_time'])
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame([10, 42], $rows->pluck('speed')->map(fn ($s) => (int) $s)->all());
    }

    public function test_incoming_batch_deduplicates_exact_coordinate_duplicates(): void
    {
        $this->skipIfMysqlGpsNotAvailable();
        Queue::fake();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);

        $frame = $this->sampleData()[0];
        (new IngestGpsData([$frame, $frame]))->handle();

        $count = DB::connection('mysql_gps')
            ->table('gps_data')
            ->where('imei', '863070046120282')
            ->where('date_time', $frame['date_time'])
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
            ->where('date_time', $good['date_time'])
            ->count();

        $this->assertSame(1, $count);
        Log::assertLogged(fn ($log) => $log->level === 'warning' && str_contains($log->message, 'missing field'));
    }

    public function test_colliding_date_times_are_preserved_without_timestamp_rewrite(): void
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
        $baseTime = Carbon::now()->subMinute()->format('Y-m-d H:i:s');
        // Packet order is A, C, B. Device timestamps are equal and must remain equal.
        $batch = [
            array_merge($base, [
                'date_time' => $baseTime,
                'coordinate' => [35.000000, 50.000000], // A
                'speed' => 10,
                'status' => 0,
            ]),
            array_merge($base, [
                'date_time' => $baseTime,
                'coordinate' => [35.000200, 50.000000], // C (farther along lat)
                'speed' => 14,
                'status' => 1,
            ]),
            array_merge($base, [
                'date_time' => $baseTime,
                'coordinate' => [35.000100, 50.000000], // B (between A and C)
                'speed' => 12,
                'status' => 0,
            ]),
        ];

        (new IngestGpsData($batch))->handle();

        $rows = DB::connection('mysql_gps')
            ->table('gps_data')
            ->where('imei', '863070046120282')
            ->where('date_time', $baseTime)
            ->orderBy('id')
            ->get(['date_time', 'speed', 'coordinate']);

        $this->assertCount(3, $rows);
        $this->assertSame([$baseTime, $baseTime, $baseTime], $rows->pluck('date_time')->map(fn ($dt) => (string) $dt)->all());
        // Equal device timestamps use insertion id as the stable tie-breaker.
        $this->assertSame([10, 14, 12], $rows->pluck('speed')->map(fn ($s) => (int) $s)->all());
    }

    public function test_skewed_past_date_time_is_preserved_for_offline_history(): void
    {
        $this->skipIfMysqlGpsNotAvailable();
        Queue::fake();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);

        $stuck = Carbon::now()->subDays(2)->format('Y-m-d H:i:s');
        $batch = [
            array_merge($this->sampleData()[0], [
                'date_time' => $stuck,
                'coordinate' => [35.1, 50.1],
                'speed' => 8,
            ]),
        ];

        (new IngestGpsData($batch))->handle();

        $row = DB::connection('mysql_gps')
            ->table('gps_data')
            ->where('imei', '863070046120282')
            ->where('tractor_id', $tractor->id)
            ->orderByDesc('id')
            ->first(['date_time']);

        $this->assertNotNull($row);
        $this->assertSame($stuck, (string) $row->date_time);
    }
}
