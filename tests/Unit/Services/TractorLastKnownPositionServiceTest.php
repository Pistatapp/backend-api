<?php

namespace Tests\Unit\Services;

use App\Http\Resources\ActiveTractorResource;
use App\Models\Tractor;
use App\Services\TractorLastKnownPositionService;
use Illuminate\Http\Request;
use Tests\TestCase;

class TractorLastKnownPositionServiceTest extends TestCase
{
    public function test_t01_returns_the_latest_valid_point_from_the_current_day(): void
    {
        $service = $this->service([
            $this->row(1, 79, '2026-11-02 08:00:00', [35.1, 51.1]),
        ]);

        $result = $service->forTractors([79]);

        $this->assertSame(1, $result[79]['id']);
        $this->assertSame('2026-11-02 08:00:00', $result[79]['event_time']);
    }

    public function test_t02_returns_a_point_from_the_previous_day(): void
    {
        $service = $this->service([
            $this->row(2, 79, '2026-11-01 18:00:00', [35.2, 51.2]),
        ]);

        $result = $service->forTractors([79]);

        $this->assertSame(2, $result[79]['id']);
    }

    public function test_t03_does_not_apply_a_24_or_30_day_limit(): void
    {
        $service = $this->service([
            $this->row(3, 79, '2026-09-01 18:00:00', [35.3, 51.3]),
        ]);

        $result = $service->forTractors([79]);

        $this->assertSame(3, $result[79]['id']);
    }

    public function test_t04_returns_no_position_when_history_is_empty(): void
    {
        $service = $this->service([]);

        $this->assertSame([], $service->forTractors([79]));
    }

    public function test_t05_skips_the_newest_invalid_coordinate_and_uses_the_older_valid_point(): void
    {
        $service = $this->service([
            $this->row(5, 79, '2026-11-02 10:00:00', [0, 0]),
            $this->row(4, 79, '2026-11-02 09:59:00', [35.4, 51.4]),
        ]);

        $result = $service->forTractors([79]);

        $this->assertSame(4, $result[79]['id']);
    }

    public function test_t06_event_time_wins_over_insert_or_fixture_order_for_buffered_data(): void
    {
        $service = $this->service([
            $this->row(6, 79, '2026-11-01 08:00:00', [35.6, 51.6]),
            $this->row(7, 79, '2026-11-02 08:00:00', [35.7, 51.7]),
        ]);

        $result = $service->forTractors([79]);

        $this->assertSame(7, $result[79]['id']);
    }

    public function test_t06b_verified_sidecar_utc_wins_over_legacy_storage_clock_order(): void
    {
        $olderStoredClock = $this->row(601, 79, '2026-11-06 08:00:00', [35.61, 51.61]);
        $olderStoredClock->event_time_utc = '2026-11-06 04:30:00';
        $newerEffectiveUtc = $this->row(602, 79, '2026-11-06 07:00:00', [35.62, 51.62]);
        $newerEffectiveUtc->event_time_utc = '2026-11-06 04:45:00';

        $service = $this->service([$olderStoredClock, $newerEffectiveUtc]);

        $result = $service->forTractors([79]);

        $this->assertSame(602, $result[79]['id']);
    }

    public function test_t07_a_point_for_another_tractor_cannot_cross_the_tractor_boundary(): void
    {
        $service = $this->service([
            $this->row(8, 75, '2026-11-02 12:00:00', [35.8, 51.8]),
            $this->row(9, 79, '2026-11-01 12:00:00', [35.9, 51.9]),
        ]);

        $result = $service->forTractors([79]);

        $this->assertArrayHasKey(79, $result);
        $this->assertArrayNotHasKey(75, $result);
    }

    public function test_t08_only_requested_authorized_tractor_ids_are_returned(): void
    {
        $service = $this->service([
            $this->row(10, 79, '2026-11-02 12:00:00', [35.0, 51.0]),
            $this->row(11, 14, '2026-11-02 12:00:00', [36.0, 52.0]),
        ]);

        $result = $service->forTractors([79]);

        $this->assertSame([79], array_keys($result));
    }

    public function test_t09_selection_does_not_depend_on_working_status(): void
    {
        $service = $this->service([
            $this->row(12, 79, '2026-10-20 12:00:00', [35.12, 51.12]),
        ]);

        // The last-known read has no is_working/date filter; selection and
        // Farm authorization remain responsibilities of the controller/query.
        $this->assertArrayHasKey(79, $service->forTractors([79]));
    }

    public function test_t10_empty_selected_day_is_independent_from_last_known_position(): void
    {
        $service = $this->service([
            $this->row(13, 79, '2026-11-01 12:00:00', [35.13, 51.13]),
        ]);

        $result = $service->forTractors([79]);

        $this->assertArrayHasKey(79, $result);
        $this->assertSame('2026-11-01 12:00:00', $result[79]['event_time']);
    }

    public function test_t11_active_resource_keeps_legacy_fields_and_adds_last_known_position(): void
    {
        $tractor = new Tractor([
            'id' => 79,
            'name' => 'Tractor 79',
            'is_working' => false,
        ]);
        $tractor->setAttribute('last_known_position', [
            'id' => 14,
            'latitude' => 35.14,
            'longitude' => 51.14,
            'event_time' => '2026-11-01 12:00:00',
            'speed' => 0,
            'status' => 0,
        ]);

        $payload = (new ActiveTractorResource($tractor))->toArray(Request::create('/'));

        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('name', $payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('last_known_position', $payload);
        $this->assertSame(35.14, $payload['last_known_position']['latitude']);
    }

    public function test_t12_uses_one_bounded_index_friendly_query_for_all_requested_tractors(): void
    {
        $service = $this->service([
            $this->row(15, 79, '2026-11-02 12:00:00', [35.15, 51.15]),
            $this->row(16, 75, '2026-11-02 12:00:00', [35.16, 51.16]),
        ]);

        $result = $service->forTractors([79, 75]);

        $this->assertCount(2, $result);
        $this->assertSame(1, $service->calls);
        $this->assertStringContainsString('UNION ALL', $service->lastQuery);
        $this->assertStringContainsString('ORDER BY date_time DESC', $service->lastQuery);
        $this->assertStringContainsString('LIMIT 32', $service->lastQuery);
        $this->assertStringNotContainsString('date_time >=', $service->lastQuery);
    }

    public function test_t13_does_not_hide_an_older_valid_point_behind_invalid_newer_rows(): void
    {
        $rows = [];
        for ($index = 0; $index < 40; $index++) {
            $rows[] = $this->row(
                1000 + $index,
                79,
                sprintf('2026-11-02 12:%02d:00', 39 - $index),
                [0, 0],
            );
        }
        $rows[] = $this->row(900, 79, '2026-11-02 11:00:00', [35.17, 51.17]);

        $service = $this->serviceWithSqlCoordinateFilter($rows);

        $result = $service->forTractors([79]);

        $this->assertSame(35.17, $result[79]['latitude']);
        $this->assertStringContainsString('JSON_TYPE(coordinate)', $service->lastQuery);
        $this->assertStringContainsString('JSON_EXTRACT(coordinate, \'$[0]\')', $service->lastQuery);
        $this->assertStringContainsString('LIMIT 32', $service->lastQuery);
    }

    public function test_t14_current_device_identity_excludes_rows_from_a_reassigned_device(): void
    {
        $service = $this->identityService([
            $this->row(140, 79, '2026-11-02 12:00:00', [36.6, 52.9], 'OLD-DEVICE'),
            $this->row(141, 79, '2026-10-01 12:00:00', [35.5, 51.5], 'CURRENT-DEVICE'),
        ]);

        $result = $service->resolveForTractors(
            [79],
            [79 => 'CURRENT-DEVICE'],
            $this->farmRectangle(),
        );

        $this->assertSame(141, $result[79]['last_known_position']['id']);
        $this->assertSame('VERIFIED_TRACTOR_POSITION', $result[79]['last_known_position_state']);
        $this->assertSame('current_assigned_device', $result[79]['last_known_position']['provenance']);
    }

    public function test_t15_outside_latest_uses_older_current_device_farm_point_and_keeps_latest_separate(): void
    {
        $service = $this->identityService([
            $this->row(150, 79, '2026-11-02 12:00:00', [36.6, 52.9], 'CURRENT-DEVICE'),
            $this->row(151, 79, '2026-10-01 12:00:00', [35.5, 51.5], 'CURRENT-DEVICE'),
        ]);

        $result = $service->resolveForTractors(
            [79],
            [79 => 'CURRENT-DEVICE'],
            $this->farmRectangle(),
        );

        $this->assertSame(151, $result[79]['last_known_position']['id']);
        $this->assertSame('STALE_VERIFIED_FARM_POSITION', $result[79]['last_known_position_state']);
        $this->assertSame(150, $result[79]['latest_device_position']['id']);
        $this->assertSame('UNVERIFIED_OUTSIDE_FARM', $result[79]['latest_device_position']['confidence']);
    }

    public function test_t16_outside_only_history_returns_unknown_instead_of_a_false_marker(): void
    {
        $service = $this->identityService([
            $this->row(160, 79, '2026-11-02 12:00:00', [36.6, 52.9], 'CURRENT-DEVICE'),
        ]);

        $result = $service->resolveForTractors(
            [79],
            [79 => 'CURRENT-DEVICE'],
            $this->farmRectangle(),
        );

        $this->assertNull($result[79]['last_known_position']);
        $this->assertSame('LOCATION_UNKNOWN', $result[79]['last_known_position_state']);
        $this->assertSame(160, $result[79]['latest_device_position']['id']);
    }

    public function test_t17_missing_current_device_rows_do_not_fall_back_to_foreign_imei_history(): void
    {
        $service = $this->identityService([
            $this->row(170, 79, '2026-11-02 12:00:00', [35.5, 51.5], 'FOREIGN-DEVICE'),
        ]);

        $result = $service->resolveForTractors(
            [79],
            [79 => 'CURRENT-DEVICE'],
            $this->farmRectangle(),
        );

        $this->assertNull($result[79]['last_known_position']);
        $this->assertSame('LOCATION_UNKNOWN', $result[79]['last_known_position_state']);
        $this->assertNull($result[79]['latest_device_position']);
    }

    public function test_t18_resource_exposes_state_without_breaking_legacy_last_known_field(): void
    {
        $tractor = new Tractor(['id' => 79, 'name' => 'Tractor 79']);
        $tractor->setAttribute('last_known_position', null);
        $tractor->setAttribute('last_known_position_state', 'LOCATION_UNKNOWN');
        $tractor->setAttribute('latest_device_position', [
            'id' => 180,
            'latitude' => 36.6,
            'longitude' => 52.9,
        ]);

        $payload = (new ActiveTractorResource($tractor))->toArray(Request::create('/'));

        $this->assertNull($payload['last_known_position']);
        $this->assertSame('LOCATION_UNKNOWN', $payload['last_known_position_state']);
        $this->assertSame(180, $payload['latest_device_position']['id']);
    }

    public function test_t19_affected_tractor_regression_never_uses_foreign_or_unverified_points(): void
    {
        $service = $this->identityService([
            $this->row(190, 73, '2026-11-02 12:00:00', [36.6, 52.9], 'FOREIGN-DEVICE'),
            $this->row(191, 118, '2026-11-02 12:00:00', [36.6, 52.9], 'DEVICE-118'),
            $this->row(192, 119, '2026-11-02 12:00:00', [35.5, 51.5], 'DEVICE-119'),
        ]);

        $result = $service->resolveForTractors(
            [73, 118, 119],
            [73 => 'DEVICE-73', 118 => 'DEVICE-118', 119 => 'DEVICE-119'],
            $this->farmRectangle(),
        );

        $this->assertNull($result[73]['last_known_position']);
        $this->assertNull($result[118]['last_known_position']);
        $this->assertSame(192, $result[119]['last_known_position']['id']);
    }

    private function service(array $rows): object
    {
        return new class($rows) extends TractorLastKnownPositionService {
            public int $calls = 0;
            public string $lastQuery = '';

            public function __construct(private readonly array $fixtureRows) {}

            protected function gpsReadSelect(string $query, array $bindings = []): array
            {
                $this->calls++;
                $this->lastQuery = $query;
                $requestedIds = array_map('intval', $bindings);
                return array_values(array_filter(
                    $this->fixtureRows,
                    static fn (object $row): bool => in_array((int) $row->tractor_id, $requestedIds, true),
                ));
            }
        };
    }

    private function serviceWithSqlCoordinateFilter(array $rows): object
    {
        return new class($rows) extends TractorLastKnownPositionService {
            public string $lastQuery = '';

            public function __construct(private readonly array $fixtureRows) {}

            protected function gpsReadSelect(string $query, array $bindings = []): array
            {
                $this->lastQuery = $query;
                $requestedIds = array_map('intval', $bindings);
                $rows = array_values(array_filter(
                    $this->fixtureRows,
                    static fn (object $row): bool => in_array((int) $row->tractor_id, $requestedIds, true)
                        && is_array($row->coordinate)
                        && count($row->coordinate) >= 2
                        && is_numeric($row->coordinate[0])
                        && is_numeric($row->coordinate[1])
                        && (float) $row->coordinate[0] >= -90
                        && (float) $row->coordinate[0] <= 90
                        && (float) $row->coordinate[1] >= -180
                        && (float) $row->coordinate[1] <= 180
                        && ! ((float) $row->coordinate[0] === 0.0 && (float) $row->coordinate[1] === 0.0),
                ));

                usort($rows, static fn (object $left, object $right): int => strcmp(
                    (string) $right->date_time,
                    (string) $left->date_time,
                ));

                return array_slice($rows, 0, 32);
            }
        };
    }

    private function identityService(array $rows): object
    {
        return new class($rows) extends TractorLastKnownPositionService {
            public function __construct(private readonly array $fixtureRows) {}

            protected function gpsReadSelect(string $query, array $bindings = []): array
            {
                $requestedIds = array_values(array_filter(
                    array_map('intval', $bindings),
                    static fn (int $value): bool => $value > 0 && $value < 1000000,
                ));
                $requestedImeis = array_values(array_filter(
                    $bindings,
                    static fn (mixed $value): bool => is_string($value) && preg_match('/^CURRENT|^FOREIGN|^OLD/', $value) === 1,
                ));

                return array_values(array_filter(
                    $this->fixtureRows,
                    static fn (object $row): bool => in_array((int) $row->tractor_id, $requestedIds, true)
                        && ($requestedImeis === [] || in_array((string) ($row->imei ?? ''), $requestedImeis, true)),
                ));
            }
        };
    }

    /** @return array<int,string> */
    private function farmRectangle(): array
    {
        return [
            '35.0,51.0',
            '36.0,51.0',
            '36.0,52.0',
            '35.0,52.0',
        ];
    }

    private function row(int $id, int $tractorId, string $dateTime, mixed $coordinate, ?string $imei = null): object
    {
        return (object) [
            'id' => $id,
            'tractor_id' => $tractorId,
            'imei' => $imei,
            'coordinate' => $coordinate,
            'speed' => 0,
            'status' => 0,
            'date_time' => $dateTime,
        ];
    }
}
