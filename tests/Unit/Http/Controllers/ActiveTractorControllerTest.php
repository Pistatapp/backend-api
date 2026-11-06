<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\Api\V1\Tractor\ActiveTractorController;
use App\Models\Driver;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Services\ActiveTractorService;
use App\Services\TractorPathPaginationService;
use App\Services\TractorPathStreamService;
use App\Services\TractorLastKnownPositionService;
use App\Services\TractorStartMovementTimeDetectionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ActiveTractorControllerTest extends TestCase
{
    public function test_active_list_returns_all_membership_rows_with_bounded_last_known_enrichment(): void
    {
        $fixtureIds = [73, 74, 75, 77, 78, 79, 118, 119];
        $tractors = new Collection(array_map(function (int $id): Tractor {
            $tractor = new Tractor;
            $tractor->setAttribute('id', $id);
            $tractor->setAttribute('name', "Fixture tractor {$id}");
            $tractor->setAttribute('is_working', false);
            $tractor->setAttribute('is_in_repair_shop', false);
            $tractor->setRelation('driver', (new Driver)->forceFill([
                'id' => 1000 + $id,
                'name' => "Driver {$id}",
                'mobile' => null,
            ]));

            // Deliberately leave the final fixture without a device. The list
            // must not perform a second GPS-based filter after the membership
            // query has selected it.
            if ($id !== 119) {
                $tractor->setRelation('gpsDevice', (new GpsDevice)->forceFill([
                    'id' => 2000 + $id,
                    'imei' => "fixture-{$id}",
                ]));
            } else {
                $tractor->setRelation('gpsDevice', null);
            }

            return $tractor;
        }, $fixtureIds));

        $relation = Mockery::mock();
        $relation->shouldReceive('active')->once()->andReturnSelf();
        $relation->shouldReceive('with')->once()->with('gpsDevice', 'driver')->andReturnSelf();
        $relation->shouldReceive('get')->once()->andReturn($tractors);

        $farm = new class($relation) extends Farm {
            private ?object $tractorRelation = null;

            public function __construct(?object $tractorRelation = null)
            {
                parent::__construct();
                $this->tractorRelation = $tractorRelation;
            }

            public function tractors()
            {
                return $this->tractorRelation;
            }
        };
        $farm->setAttribute('coordinates', []);

        $startMovement = Mockery::mock(TractorStartMovementTimeDetectionService::class);
        $startMovement->shouldReceive('detectStartMovementTimeForTractors')
            ->once()
            ->andReturn($tractors);

        $lastKnown = Mockery::mock(TractorLastKnownPositionService::class);
        $lastKnown->shouldReceive('resolveForTractors')
            ->once()
            ->andReturn([
                75 => $this->knownPosition(75),
                79 => $this->knownPosition(79),
                119 => [
                    'last_known_position' => null,
                    'last_known_position_state' => 'LOCATION_UNKNOWN',
                    'latest_device_position' => null,
                ],
            ]);

        $controller = new ActiveTractorController(
            Mockery::mock(ActiveTractorService::class),
            Mockery::mock(TractorPathStreamService::class),
            Mockery::mock(TractorPathPaginationService::class),
            $startMovement,
            $lastKnown,
        );

        $request = Request::create('/api/farms/77/tractors/active', 'GET');
        $payload = $controller->index($request, $farm)->toArray($request);

        $this->assertCount(8, $payload);
        $this->assertSame($fixtureIds, array_column($payload, 'id'));
        $this->assertContains(75, array_column($payload, 'id'));
        $this->assertContains(79, array_column($payload, 'id'));
        $this->assertSame(75, $payload[2]['last_known_position']['id']);
        $this->assertSame(79, $payload[5]['last_known_position']['id']);
        $this->assertNull($payload[7]['last_known_position']);
        $this->assertSame('LOCATION_UNKNOWN', $payload[7]['last_known_position_state']);
        $this->assertNull($payload[7]['latest_device_position']);
    }

    public function test_active_list_source_has_no_historical_or_last_known_resolver_dependency(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4).'/app/Http/Controllers/Api/V1/Tractor/ActiveTractorController.php'
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('TractorLastKnownPositionService', $source);
        $this->assertStringNotContainsString('resolveForWindow', $source);
        $this->assertStringContainsString('resolveForTractors', $source);
    }

    /** @return array<string,mixed> */
    private function knownPosition(int $id): array
    {
        return [
            'last_known_position' => [
                'id' => $id,
                'latitude' => 35.0,
                'longitude' => 51.0,
                'event_time' => '2026-11-06 10:00:00',
            ],
            'last_known_position_state' => 'VERIFIED_TRACTOR_POSITION',
            'latest_device_position' => null,
        ];
    }
}
