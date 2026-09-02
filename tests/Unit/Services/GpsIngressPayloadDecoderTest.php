<?php

namespace Tests\Unit\Services;

use App\Services\GpsIngressPayloadDecoder;
use Tests\TestCase;

class GpsIngressPayloadDecoderTest extends TestCase
{
    private GpsIngressPayloadDecoder $decoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decoder = new GpsIngressPayloadDecoder();
    }

    public function test_decodes_normalized_single_and_preserves_event_fields(): void
    {
        $item = [
            'event_id' => 'hoosh-1',
            'imei' => '868064071065855',
            'coordinate' => [35.940486, 50.059037],
            'date_time' => '2026-08-31 10:00:00',
            'speed' => 4,
            'status' => 1,
            'directions' => ['ew' => 3, 'ns' => 1],
        ];

        $results = $this->decoder->decode(json_encode(['data' => [$item]], JSON_THROW_ON_ERROR));

        $this->assertCount(1, $results);
        $this->assertSame('hoosh-1', $results[0]['data']['event_id']);
        $this->assertSame($item['coordinate'], $results[0]['data']['coordinate']);
        $this->assertNull($results[0]['error_reason']);
    }

    public function test_recovers_glued_objects_noise_crlf_nul_and_nonstandard_escape(): void
    {
        $a = '+Hooshnic\\:V1.07,3556.42915,05003.5422,000,260831,100000,004,004,1,3,1,868064071065855';
        $b = '+Hooshnic:V1.07,3556.50000,05003.6000,000,260831,100001,005,004,1,3,1,868064071065855';
        $raw = "NOISE\r\n\0{". '"data":"'.$a.'"}' . '{"data":"'.$b.'"}AT+CIPSTART="TCP","api",TRAILING';

        $results = $this->decoder->decode($raw);

        $this->assertCount(2, $results);
        $this->assertSame(35.940486, $results[0]['data']['coordinate'][0]);
        $this->assertSame(50.059037, $results[0]['data']['coordinate'][1]);
        $this->assertSame('868064071065855', $results[0]['data']['imei']);
        $this->assertSame('260831100000', $results[0]['data']['device_recorded_at_raw']);
        $this->assertSame(35.941667, $results[1]['data']['coordinate'][0]);
    }

    public function test_one_bad_item_does_not_discard_good_items(): void
    {
        $good = ['data' => '+Hooshnic:V1.07,3556.42915,05003.5422,000,260831,100000,004,004,1,3,1,868064071065855'];
        $bad = ['data' => '+Hooshnic:V1.07,not-a-coordinate,05003.5422,000,260831,100000,004,004,1,3,1,868064071065855'];

        $results = $this->decoder->decode(json_encode([$good, $bad], JSON_THROW_ON_ERROR));

        $this->assertCount(2, $results);
        $this->assertNotNull($results[0]['data']);
        $this->assertNull($results[1]['data']);
        $this->assertNotSame('', $results[1]['error_reason']);
        $this->assertStringContainsString('NMEA', $results[1]['error_reason']);
    }

    public function test_decodes_a_thousand_item_batch_without_loss(): void
    {
        $items = [];
        for ($i = 0; $i < 1000; $i++) {
            $items[] = [
                'data' => '+Hooshnic:V1.07,3556.42915,05003.5422,000,260831,100000,004,004,1,3,1,868064071065855',
            ];
        }

        $results = $this->decoder->decode(json_encode($items, JSON_THROW_ON_ERROR));

        $this->assertCount(1000, $results);
        $this->assertCount(1000, array_filter($results, static fn (array $result) => $result['data'] !== null));
    }

    public function test_decodes_the_tracked_legacy_capture_without_silent_item_loss(): void
    {
        $path = base_path('Wifi Gps Logs.txt');
        $this->assertFileExists($path);

        $results = $this->decoder->decode((string) file_get_contents($path));

        $this->assertCount(6271, $results);
        $this->assertCount(6271, array_filter($results, static fn (array $result) => $result['data'] !== null));
        $this->assertSame(['861292053604220'], array_values(array_unique(array_map(
            static fn (array $result) => $result['data']['imei'],
            $results
        ))));
    }
}
