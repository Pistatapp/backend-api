<?php

namespace Tests\Feature;

use App\Jobs\ProcessGpsData;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GpsReportsEndpointTest extends TestCase
{
    use RefreshDatabase;

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
     * Sample payload matching the GPS device format.
     */
    private function samplePayload(): array
    {
        return [
            'data' => [
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
            ],
        ];
    }

    public function test_gps_reports_endpoint_accepts_payload_and_returns_200(): void
    {
        $response = $this->postJson('/api/gps/reports', $this->samplePayload());

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_gps_reports_endpoint_dispatches_process_gps_data_job_with_correct_data(): void
    {
        Queue::fake();

        $payload = $this->samplePayload();
        $this->postJson('/api/gps/reports', $payload);

        Queue::assertPushed(ProcessGpsData::class, function (ProcessGpsData $job) use ($payload) {
            $this->assertSame($payload['data'], $job->data);
            $this->assertCount(2, $job->data);
            $this->assertSame('863070046120282', $job->data[0]['imei']);
            $this->assertSame([35.937893, 50.065403], $job->data[0]['coordinate']);
            $this->assertSame(['ew' => 3, 'ns' => 1], $job->data[0]['directions']);
            $this->assertSame('2026-02-25 18:49:45', $job->data[0]['date_time']);
            return true;
        });
    }

    public function test_gps_reports_endpoint_accepts_empty_data_array(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/gps/reports', ['data' => []]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        Queue::assertPushed(ProcessGpsData::class, function (ProcessGpsData $job) {
            return $job->data === [];
        });
    }
}
