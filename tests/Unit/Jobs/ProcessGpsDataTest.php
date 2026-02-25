<?php

namespace Tests\Unit\Jobs;

use App\Jobs\BroadcastGpsEvents;
use App\Jobs\ProcessGpsData;
use App\Jobs\StoreGpsData;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessGpsDataTest extends TestCase
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
        ProcessGpsData::dispatch($data);

        Queue::assertPushedOn('gps-processing', ProcessGpsData::class);
    }

    public function test_receives_data_and_dispatches_store_gps_data_when_tractor_found(): void
    {
        Queue::fake();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);

        $data = $this->sampleData();
        $job = new ProcessGpsData($data);
        $job->handle(app(\App\Services\ParseDataService::class));

        Queue::assertPushed(StoreGpsData::class, function (StoreGpsData $storeJob) use ($tractor, $data) {
            $this->assertSame($data, $storeJob->data);
            $this->assertSame($tractor->id, $storeJob->tractorId);
            return true;
        });
    }

    public function test_dispatches_broadcast_gps_events_when_tractor_found(): void
    {
        Queue::fake();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);

        $data = $this->sampleData();
        $job = new ProcessGpsData($data);
        $job->handle(app(\App\Services\ParseDataService::class));

        Queue::assertPushed(BroadcastGpsEvents::class, function (BroadcastGpsEvents $broadcastJob) use ($tractor, $data) {
            $this->assertSame($data, $broadcastJob->data);
            $this->assertSame($tractor->id, $broadcastJob->tractorId);
            $this->assertSame('863070046120282', $broadcastJob->deviceImei);
            return true;
        });
    }

    public function test_does_not_dispatch_store_gps_data_when_no_tractor_for_imei(): void
    {
        Queue::fake();

        $data = $this->sampleData();
        // IMEI not assigned to any tractor
        $job = new ProcessGpsData($data);
        $job->handle(app(\App\Services\ParseDataService::class));

        Queue::assertNotPushed(StoreGpsData::class);
        Queue::assertNotPushed(BroadcastGpsEvents::class);
    }

    public function test_uses_first_item_imei_to_resolve_tractor(): void
    {
        Queue::fake();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        GpsDevice::factory()->create([
            'tractor_id' => $tractor->id,
            'imei' => '863070046120282',
        ]);

        // First item IMEI resolves to tractor; second item has different IMEI
        $data = $this->sampleData();
        $job = new ProcessGpsData($data);
        $job->handle(app(\App\Services\ParseDataService::class));

        Queue::assertPushed(StoreGpsData::class, function (StoreGpsData $storeJob) use ($tractor) {
            return $storeJob->tractorId === $tractor->id && count($storeJob->data) === 2;
        });
    }
}
