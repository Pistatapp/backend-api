<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\ParseDataService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

class ParseDataServiceTest extends TestCase
{
    private ParseDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ParseDataService();
        Carbon::setTestNow('2024-01-24 07:02:00');
    }

    #[Test]
    public function it_parses_valid_gps_data()
    {
        $data = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070200,018,000,1,863070043386100']
        ]);

        $result = $this->service->parse($data);

        $this->assertCount(1, $result);
        $this->assertEquals([
            'coordinate' => [
                34.884065,
                50.599625
            ],
            'speed' => 18,
            'status' => 1,
            'imei' => '863070043386100',
            'is_stopped' => false,
            'stoppage_time' => 0,
            'is_starting_point' => false,
            'is_ending_point' => false,
            'date_time' => Carbon::createFromFormat('ymdHis', '240124070200')->addHours(3)->addMinutes(30),
        ], $result[0]);
    }

    #[Test]
    public function it_filters_out_invalid_format()
    {
        $data = json_encode([
            ['data' => 'invalid format'],
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070200,018,000,1,863070043386100']
        ]);

        $result = $this->service->parse($data);

        $this->assertCount(1, $result);
        $this->assertEquals('863070043386100', $result[0]['imei']);
    }

    #[Test]
    public function it_filters_out_old_dates()
    {
        $data = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,230124,070200,018,000,1,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070200,018,000,1,863070043386100']
        ]);

        $result = $this->service->parse($data);

        $this->assertCount(1, $result);
        $this->assertEquals('240124 103200', $result[0]['date_time']->format('dmy His'));
    }

    #[Test]
    public function it_sorts_results_by_datetime()
    {
        $data = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070200,018,000,1,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070100,018,000,1,863070043386100']
        ]);

        $result = $this->service->parse($data);

        $this->assertCount(2, $result);
        $this->assertEquals('103100', $result[0]['date_time']->format('His'));
        $this->assertEquals('103200', $result[1]['date_time']->format('His'));
    }
}
