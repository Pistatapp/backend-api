<?php

namespace App\Services;

use App\Traits\GpsReadConnection;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a safe display position without confusing a GPS device's history
 * with the tractor to which that device is currently assigned.
 *
 * This service is read-only. The controller supplies the current device IMEI
 * and the authorized farm boundary. Historical GPS rows are never rewritten.
 */
class TractorLastKnownPositionService
{
    use GpsReadConnection;

    private const CANDIDATE_ROWS_PER_TRACTOR = 32;

    private const VALID_COORDINATE_SQL = <<<'SQL'
        JSON_TYPE(coordinate) = 'ARRAY'
        AND JSON_LENGTH(coordinate) >= 2
        AND JSON_TYPE(JSON_EXTRACT(coordinate, '$[0]')) IN ('INTEGER', 'DOUBLE', 'DECIMAL')
        AND JSON_TYPE(JSON_EXTRACT(coordinate, '$[1]')) IN ('INTEGER', 'DOUBLE', 'DECIMAL')
        AND CAST(JSON_UNQUOTE(JSON_EXTRACT(coordinate, '$[0]')) AS DECIMAL(12,8)) BETWEEN -90 AND 90
        AND CAST(JSON_UNQUOTE(JSON_EXTRACT(coordinate, '$[1]')) AS DECIMAL(12,8)) BETWEEN -180 AND 180
        AND NOT (
            CAST(JSON_UNQUOTE(JSON_EXTRACT(coordinate, '$[0]')) AS DECIMAL(12,8)) = 0
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(coordinate, '$[1]')) AS DECIMAL(12,8)) = 0
        )
        SQL;

    /**
     * Backward-compatible view used by existing callers: only safe display
     * positions are returned. A location classified as unknown is omitted.
     *
     * @param iterable<int|string> $tractorIds
     * @param array<int|string,string>|null $assignedImeis
     * @param array<int,mixed>|null $farmCoordinates
     * @param array<int|string,string>|null $assignedSince
     * @return array<int,array<string,mixed>>
     */
    public function forTractors(
        iterable $tractorIds,
        ?array $assignedImeis = null,
        ?array $farmCoordinates = null,
        ?array $assignedSince = null,
    ): array {
        $resolved = $this->resolveForTractors($tractorIds, $assignedImeis, $farmCoordinates, $assignedSince);
        $positions = [];

        foreach ($resolved as $tractorId => $resolution) {
            if (($resolution['last_known_position'] ?? null) !== null) {
                $positions[(int) $tractorId] = $resolution['last_known_position'];
            }
        }

        return $positions;
    }

    /**
     * Return the safe display position plus non-destructive provenance data.
     *
     * When the newest point from the currently assigned device is outside the
     * authorized farm boundary, an older point inside that boundary is used as
     * the last verified tractor position. If no such point exists, the safe
     * display position is null and the raw latest device position is exposed
     * separately for authorized clients; legacy Android therefore cannot show
     * an unverified workshop marker as a tractor marker.
     *
     * @param iterable<int|string> $tractorIds
     * @param array<int|string,string>|null $assignedImeis
     * @param array<int,mixed>|null $farmCoordinates
     * @param array<int|string,string>|null $assignedSince
     * @return array<int,array{last_known_position:?array,last_known_position_state:string,latest_device_position:?array}>
     */
    public function resolveForTractors(
        iterable $tractorIds,
        ?array $assignedImeis = null,
        ?array $farmCoordinates = null,
        ?array $assignedSince = null,
    ): array {
        $ids = [];
        foreach ($tractorIds as $tractorId) {
            $id = (int) $tractorId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $polygon = $this->normalizePolygon($farmCoordinates);
        $boundingBox = $polygon === null ? null : $this->boundingBox($polygon);
        $identityScoped = $assignedImeis !== null;
        $branches = [];
        $bindings = [];

        foreach (array_values($ids) as $id) {
            $imei = trim((string) ($assignedImeis[$id] ?? ''));

            // A missing current assignment is not permission to fall back to
            // arbitrary historical rows for this tractor.
            if ($identityScoped && $imei === '') {
                continue;
            }

            $where = [
                'tractor_id = ?',
                self::VALID_COORDINATE_SQL,
            ];
            $branchBindings = [$id];
            $assignmentStartedAt = trim((string) ($assignedSince[$id] ?? ''));
            if ($assignmentStartedAt !== '') {
                // gps_devices has no assignment-history table. The current
                // device row's creation time is a safe lower bound that keeps
                // pre-assignment history out without inventing an install date.
                $where[] = 'date_time >= ?';
                $branchBindings[] = $assignmentStartedAt;
            }

            $branches[] = '(SELECT g.id, g.tractor_id, g.imei, g.coordinate, g.speed, g.status, g.date_time,
                                   p.event_time_utc, p.event_date_jalali, p.event_time_tehran,
                                   p.timezone, p.source_protocol, p.event_id,
                                   0 AS candidate_scope
                            FROM gps_data g
                            LEFT JOIN gps_event_time_provenance p
                              ON p.gps_data_id = g.id AND p.gps_date_time = g.date_time
                            WHERE '.implode(' AND ', $where).'
                            ORDER BY g.date_time DESC
                            LIMIT '.self::CANDIDATE_ROWS_PER_TRACTOR.')';
            array_push($bindings, ...$branchBindings);

            // This second bounded branch lets an older verified farm point be
            // found even when the newest device points are outside the farm.
            // The exact polygon check still happens in PHP.
            if ($boundingBox !== null) {
                [$minLat, $maxLat, $minLon, $maxLon] = $boundingBox;
                $farmWhere = $where;
                $farmWhere[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(coordinate, '$[0]')) AS DECIMAL(12,8)) BETWEEN ? AND ?";
                $farmWhere[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(coordinate, '$[1]')) AS DECIMAL(12,8)) BETWEEN ? AND ?";
                $farmBindings = array_merge($branchBindings, [$minLat, $maxLat, $minLon, $maxLon]);

                $branches[] = '(SELECT g.id, g.tractor_id, g.imei, g.coordinate, g.speed, g.status, g.date_time,
                                       p.event_time_utc, p.event_date_jalali, p.event_time_tehran,
                                       p.timezone, p.source_protocol, p.event_id,
                                       1 AS candidate_scope
                                FROM gps_data g
                                LEFT JOIN gps_event_time_provenance p
                                  ON p.gps_data_id = g.id AND p.gps_date_time = g.date_time
                                WHERE '.implode(' AND ', $farmWhere).'
                                ORDER BY g.date_time DESC
                                LIMIT '.self::CANDIDATE_ROWS_PER_TRACTOR.')';
                array_push($bindings, ...$farmBindings);
            }
        }

        $resolutions = [];
        foreach ($ids as $id) {
            $resolutions[$id] = [
                'last_known_position' => null,
                'last_known_position_state' => 'LOCATION_UNKNOWN',
                'latest_device_position' => null,
            ];
        }

        if ($branches === []) {
            return $resolutions;
        }

        try {
            $rows = $this->gpsReadSelect(implode(' UNION ALL ', $branches), $bindings);
        } catch (\Throwable $exception) {
            Log::warning('Unable to load last-known tractor positions', [
                'tractor_count' => count($ids),
                'error' => $exception->getMessage(),
            ]);

            return $resolutions;
        }

        // UNION branch order is not a contract. Sort the bounded result set by
        // event time and id before selecting the latest device/farm points.
        usort($rows, static function (object $left, object $right): int {
            $tractorOrder = ((int) ($left->tractor_id ?? 0)) <=> ((int) ($right->tractor_id ?? 0));
            if ($tractorOrder !== 0) {
                return $tractorOrder;
            }

            $dateOrder = strcmp((string) ($right->date_time ?? ''), (string) ($left->date_time ?? ''));
            return $dateOrder !== 0
                ? $dateOrder
                : ((int) ($right->id ?? 0) <=> (int) ($left->id ?? 0));
        });

        $latest = [];
        $latestFarm = [];
        foreach ($rows as $row) {
            $tractorId = (int) ($row->tractor_id ?? 0);
            if (!isset($ids[$tractorId])) {
                continue;
            }

            $expectedImei = trim((string) ($assignedImeis[$tractorId] ?? ''));
            if ($identityScoped && $expectedImei !== ''
                && (string) ($row->imei ?? '') !== $expectedImei) {
                continue;
            }

            [$latitude, $longitude] = $this->parseCoordinate($row->coordinate ?? null);
            if (!$this->isValidCoordinate($latitude, $longitude)) {
                continue;
            }

            $position = $this->positionFromRow($row, $latitude, $longitude);
            if (!isset($latest[$tractorId])) {
                $latest[$tractorId] = $position;
            }

            if ($polygon !== null
                && $this->isInsidePolygon($latitude, $longitude, $polygon)
                && !isset($latestFarm[$tractorId])) {
                $latestFarm[$tractorId] = $position;
            }
        }

        foreach ($ids as $id) {
            $latestDevice = $latest[$id] ?? null;
            if ($latestDevice === null) {
                continue;
            }

            // Without an authorized boundary, identity-scoped latest-device
            // data remains a real device position but is not called farm-
            // verified. The controller normally supplies the farm boundary.
            if ($polygon === null) {
                $unverified = $this->withProvenance(
                    $latestDevice,
                    'latest_device_gps',
                    'current_assigned_device',
                    'DEVICE_POSITION_UNVERIFIED',
                );
                $resolutions[$id] = [
                    'last_known_position' => $unverified,
                    'last_known_position_state' => 'DEVICE_POSITION_UNVERIFIED',
                    'latest_device_position' => $unverified,
                ];
                continue;
            }

            if ($this->isInsidePolygon($latestDevice['latitude'], $latestDevice['longitude'], $polygon)) {
                $resolutions[$id] = [
                    'last_known_position' => $this->withProvenance(
                        $latestDevice,
                        'gps_device',
                        'current_assigned_device',
                        'VERIFIED_TRACTOR_POSITION',
                    ),
                    'last_known_position_state' => 'VERIFIED_TRACTOR_POSITION',
                    'latest_device_position' => $this->withProvenance(
                        $latestDevice,
                        'latest_device_gps',
                        'current_assigned_device',
                        'VERIFIED_TRACTOR_POSITION',
                    ),
                ];
                continue;
            }

            if (isset($latestFarm[$id])) {
                $resolutions[$id] = [
                    'last_known_position' => $this->withProvenance(
                        $latestFarm[$id],
                        'last_verified_tractor_position',
                        'current_assigned_device_farm_history',
                        'STALE_VERIFIED_FARM_POSITION',
                    ),
                    'last_known_position_state' => 'STALE_VERIFIED_FARM_POSITION',
                    'latest_device_position' => $this->withProvenance(
                        $latestDevice,
                        'latest_device_gps',
                        'current_assigned_device_outside_farm',
                        'UNVERIFIED_OUTSIDE_FARM',
                    ),
                ];
            } else {
                // Do not show a workshop/foreign-city point as a tractor
                // marker when no current-device point is verified in the farm.
                $resolutions[$id] = [
                    'last_known_position' => null,
                    'last_known_position_state' => 'LOCATION_UNKNOWN',
                    'latest_device_position' => $this->withProvenance(
                        $latestDevice,
                        'latest_device_gps',
                        'current_assigned_device_outside_farm',
                        'UNVERIFIED_OUTSIDE_FARM',
                    ),
                ];
            }
        }

        return $resolutions;
    }

    /** @return array<string,mixed> */
    private function positionFromRow(object $row, float $latitude, float $longitude): array
    {
        return [
            'id' => (int) $row->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'event_time' => $row->date_time,
            'event_time_utc' => $row->event_time_utc ?? null,
            'event_date_jalali' => $row->event_date_jalali ?? null,
            // This is the Gateway-supplied civil Tehran clock. PiStat only
            // exposes the clock portion; it never derives it from UTC.
            'event_time_tehran' => isset($row->event_time_tehran)
                ? substr((string) $row->event_time_tehran, 11, 8)
                : null,
            'timezone' => $row->timezone ?? null,
            'source_protocol' => $row->source_protocol ?? null,
            'event_id' => $row->event_id ?? null,
            'speed' => (int) ($row->speed ?? 0),
            'status' => (int) ($row->status ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function withProvenance(
        array $position,
        string $source,
        string $provenance,
        string $confidence,
    ): array {
        return array_merge($position, [
            'source' => $source,
            'provenance' => $provenance,
            'confidence' => $confidence,
        ]);
    }

    /** @return array{0:float,1:float,2:float,3:float}|null */
    private function boundingBox(array $polygon): ?array
    {
        if (count($polygon) < 3) {
            return null;
        }

        $latitudes = array_column($polygon, 0);
        $longitudes = array_column($polygon, 1);

        return [
            min($latitudes),
            max($latitudes),
            min($longitudes),
            max($longitudes),
        ];
    }

    /** @return array<int,array{0:float,1:float}>|null */
    private function normalizePolygon(?array $coordinates): ?array
    {
        if ($coordinates === null) {
            return null;
        }

        $polygon = [];
        foreach ($coordinates as $point) {
            if (is_string($point)) {
                $point = explode(',', $point, 2);
            }

            if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                continue;
            }

            $polygon[] = [(float) $point[0], (float) $point[1]];
        }

        return count($polygon) >= 3 ? $polygon : null;
    }

    /** @param array<int,array{0:float,1:float}> $polygon */
    private function isInsidePolygon(float $latitude, float $longitude, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$yi, $xi] = $polygon[$i];
            [$yj, $xj] = $polygon[$j];

            if ((($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < (($xj - $xi) * ($latitude - $yi) / (($yj - $yi) ?: PHP_FLOAT_EPSILON)) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /** @return array{0:float,1:float} */
    private function parseCoordinate(mixed $coordinate): array
    {
        if (is_string($coordinate)) {
            $trimmed = trim($coordinate);
            $coordinate = str_starts_with($trimmed, '[')
                ? json_decode($trimmed, true)
                : explode(',', $trimmed, 2);
        }

        return [
            (float) ($coordinate[0] ?? 0),
            (float) ($coordinate[1] ?? 0),
        ];
    }

    private function isValidCoordinate(float $latitude, float $longitude): bool
    {
        return $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180
            && !($latitude === 0.0 && $longitude === 0.0);
    }
}
