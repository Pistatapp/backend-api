<?php

namespace Tests\Unit\Jobs;

use App\Jobs\BroadcastGpsEvents;
use App\Jobs\IngestGpsData;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        $data = $this->sampleData();
        $job = new IngestGpsData($data);
        $job->handle();

        Queue::assertNotPushed(BroadcastGpsEvents::class);
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
}
