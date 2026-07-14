<?php

namespace Tests\Feature;

use App\Jobs\IngestGpsData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GpsReportsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED_IP = '94.101.187.206';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'services.gps_reports.rate_limit_exempt_ips' => [self::ALLOWED_IP, '127.0.0.1'],
        ]);
    }

    private function skipIfMysqlGpsNotAvailable(): void
    {
        try {
            DB::connection('mysql_gps')->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('MySQL GPS connection not available: ' . $e->getMessage());
        }
    }

    private function postGpsReport(array $payload, ?string $ip = self::ALLOWED_IP)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/gps/reports', $payload);
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
        Queue::fake();

        $response = $this->postGpsReport($this->samplePayload());

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_gps_reports_endpoint_dispatches_ingest_gps_data_job_with_correct_data(): void
    {
        Queue::fake();

        $payload = $this->samplePayload();
        $this->postGpsReport($payload);

        Queue::assertPushed(IngestGpsData::class, function (IngestGpsData $job) use ($payload) {
            $this->assertEquals($payload['data'], $job->data);
            $this->assertCount(2, $job->data);
            $this->assertSame('863070046120282', $job->data[0]['imei']);
            $this->assertSame([35.937893, 50.065403], $job->data[0]['coordinate']);
            $this->assertSame(['ew' => 3, 'ns' => 1], $job->data[0]['directions']);
            $this->assertSame('2026-02-25 18:49:45', $job->data[0]['date_time']);

            return true;
        });
    }

    public function test_gps_reports_endpoint_rejects_empty_data_array(): void
    {
        Queue::fake();

        $response = $this->postGpsReport(['data' => []]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_gps_reports_endpoint_filters_empty_objects_from_hooshnic_batches(): void
    {
        Queue::fake();

        $payload = $this->samplePayload();
        $payload['data'][] = [];

        $response = $this->postGpsReport($payload);

        $response->assertStatus(200);
        Queue::assertPushed(IngestGpsData::class, function (IngestGpsData $job) {
            return count($job->data) === 2;
        });
    }

    public function test_gps_reports_endpoint_rejects_malformed_payload(): void
    {
        Queue::fake();

        $response = $this->postGpsReport([
            'data' => [
                [
                    'imei' => '863070046120282',
                ],
            ],
        ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_gps_reports_endpoint_returns_403_for_non_allowlisted_ip(): void
    {
        Queue::fake();

        $response = $this->postGpsReport($this->samplePayload(), '203.0.113.10');

        $response->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_gps_reports_endpoint_is_not_rate_limited_for_allowlisted_ip(): void
    {
        Queue::fake();

        for ($i = 0; $i < 61; $i++) {
            $response = $this->postGpsReport($this->samplePayload());

            $response->assertStatus(200);
        }
    }
}
