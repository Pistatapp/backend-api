<?php

namespace App\Services;

use App\Models\Tractor;
use App\Traits\GpsReadConnection;
use Carbon\Carbon;
use PDO;
use RuntimeException;

/**
 * Bounded, snapshot-consistent historical path pages for Android and other
 * clients that can consume the v2 contract.
 */
class TractorPathPaginationService
{
    use GpsReadConnection;

    private const NULL_BATCH_ORDER = 2147483647;
    private const ZERO_STOPPAGE_TIME = '00:00:00';

    private ?bool $hasBatchIndexColumn = null;

    public function __construct(
        private TractorTrajectoryService $trajectoryService,
        private DeviceTrajectoryProfileResolver $profileResolver,
    ) {}

    /**
     * @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    public function page(Tractor $tractor, Carbon $date, ?string $cursor, int $limit): array
    {
        $limit = min(
            (int) config('trajectory.historical_path.max_page_size', 1000),
            max(1, $limit),
        );
        [$startOfDay, $endOfDay] = $this->resolvePathDateWindow($date);
        $dayKey = Carbon::parse($startOfDay, config('app.timezone', 'Asia/Tehran'))->toDateString();
        $state = $cursor === null ? null : HistoricalPathCursor::decode($cursor);

        $this->validateCursorState($state, $tractor, $dayKey);
        $highWaterId = $state === null
            ? $this->readHighWaterId($tractor, $startOfDay, $endOfDay)
            : (int) ($state['high_water_id'] ?? 0);
        $contextSize = max(1, (int) config('trajectory.historical_path.context_rows', 64));
        $after = is_array($state['after'] ?? null) ? $state['after'] : null;

        $contextRows = $after === null
            ? []
            : $this->fetchRowsBefore(
                $tractor->id,
                $startOfDay,
                $endOfDay,
                $highWaterId,
                $after,
                $contextSize,
            );
        $forwardRows = $this->fetchRowsAfter(
            $tractor->id,
            $startOfDay,
            $endOfDay,
            $highWaterId,
            $after,
            $limit + $contextSize,
        );
        $coreRows = array_slice($forwardRows, 0, $limit);
        $hasMore = count($forwardRows) > $limit;

        if ($coreRows === []) {
            return [
                'data' => [],
                'meta' => $this->meta(
                    $highWaterId,
                    0,
                    0,
                    0,
                    0,
                    false,
                    null,
                ),
            ];
        }

        $analysis = $this->trajectoryService->analyze(
            array_merge($contextRows, $forwardRows),
            $this->profileResolver->resolve($tractor),
        );
        $analyzedById = [];
        foreach ($analysis['rows'] as $row) {
            $analyzedById[(string) ($row['id'] ?? '')] = $row;
        }

        $segmentOffset = $this->segmentOffset($state, $contextRows, $analyzedById);
        $coreIds = array_fill_keys(array_map(
            static fn (array $row): string => (string) $row['id'],
            $coreRows,
        ), true);
        $data = [];
        $verifiedCount = 0;
        $rejectedCount = 0;

        // The analyzer sorts by the same deterministic key as the SQL query.
        // Filtering by the core IDs removes lookahead/context rows without
        // changing the order or joining independent segments.
        foreach ($analysis['rows'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if (! isset($coreIds[$id])) {
                continue;
            }

            $isDisplayPoint = (bool) ($row['is_display_point'] ?? false);
            if ($isDisplayPoint) {
                $verifiedCount++;
                $data[] = $this->formatPoint($row, $segmentOffset);
            } else {
                $rejectedCount++;
            }
        }

        $started = (bool) ($state['started'] ?? false);
        if (! $started && $data !== []) {
            $data[0]['is_starting_point'] = true;
            $started = true;
        }
        if (! $hasMore && $data !== []) {
            $data[count($data) - 1]['is_ending_point'] = true;
        }

        $nextCursor = null;
        if ($hasMore) {
            $lastCore = $coreRows[count($coreRows) - 1];
            $lastAnalyzed = $analyzedById[(string) $lastCore['id']] ?? null;
            if (! is_array($lastAnalyzed)) {
                throw new RuntimeException('Historical path page lost its cursor boundary.');
            }

            $nextCursor = HistoricalPathCursor::encode([
                'tractor_id' => (int) $tractor->id,
                'date' => $dayKey,
                'high_water_id' => $highWaterId,
                'after' => $this->orderKey($lastCore),
                'last_segment_id' => (int) ($lastAnalyzed['segment_id'] ?? 0) + $segmentOffset,
                'started' => $started,
            ]);
        }

        return [
            'data' => $data,
            'meta' => $this->meta(
                $highWaterId,
                count($coreRows),
                $verifiedCount,
                $rejectedCount,
                count($data),
                $hasMore,
                $nextCursor,
            ),
        ];
    }

    /** @param array<string,mixed>|null $state */
    private function validateCursorState(?array $state, Tractor $tractor, string $dayKey): void
    {
        if ($state === null) {
            return;
        }

        if ((int) ($state['tractor_id'] ?? 0) !== (int) $tractor->id
            || (string) ($state['date'] ?? '') !== $dayKey
            || (int) ($state['high_water_id'] ?? 0) < 0
            || ! is_array($state['after'] ?? null)
        ) {
            throw new \InvalidArgumentException('GPS path cursor does not match this tractor and date.');
        }
    }

    /** @param array<string,mixed>|null $state @param array<int,array<string,mixed>> $contextRows @param array<string,array<string,mixed>> $analyzedById */
    private function segmentOffset(?array $state, array $contextRows, array $analyzedById): int
    {
        if ($state === null || $contextRows === []) {
            return 0;
        }

        $contextLast = $contextRows[count($contextRows) - 1];
        $analyzedContextLast = $analyzedById[(string) ($contextLast['id'] ?? '')] ?? null;
        if (! is_array($analyzedContextLast)) {
            return 0;
        }

        return (int) ($state['last_segment_id'] ?? 0) - (int) ($analyzedContextLast['segment_id'] ?? 0);
    }

    private function readHighWaterId(Tractor $tractor, string $startOfDay, string $endOfDay): int
    {
        $row = $this->gpsReadSelectOne(
            'SELECT MAX(id) AS high_water_id FROM gps_data WHERE tractor_id = ? AND date_time >= ? AND date_time < ?',
            [$tractor->id, $startOfDay, $endOfDay],
        );

        return max(0, (int) ($row->high_water_id ?? 0));
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchRowsAfter(
        int $tractorId,
        string $startOfDay,
        string $endOfDay,
        int $highWaterId,
        ?array $after,
        int $limit,
    ): array {
        $condition = '';
        $bindings = [
            'tractor_id' => $tractorId,
            'start_of_day' => $startOfDay,
            'end_of_day' => $endOfDay,
            'high_water_id' => $highWaterId,
        ];
        if ($after !== null) {
            $condition = ' AND (date_time > :after_date_time OR (date_time = :after_date_time AND (COALESCE(batch_index, '.self::NULL_BATCH_ORDER.') > :after_batch_order OR (COALESCE(batch_index, '.self::NULL_BATCH_ORDER.') = :after_batch_order AND id > :after_id))))';
            $bindings = [
                'tractor_id' => $tractorId,
                'start_of_day' => $startOfDay,
                'end_of_day' => $endOfDay,
                'high_water_id' => $highWaterId,
                'after_date_time' => (string) ($after['date_time'] ?? ''),
                'after_batch_order' => (int) ($after['batch_order'] ?? self::NULL_BATCH_ORDER),
                'after_id' => (int) ($after['id'] ?? 0),
            ];
        }

        return $this->runOrderedQuery(
            'ASC',
            $condition,
            $bindings,
            $limit,
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchRowsBefore(
        int $tractorId,
        string $startOfDay,
        string $endOfDay,
        int $highWaterId,
        array $after,
        int $limit,
    ): array {
        $condition = ' AND (date_time < :before_date_time OR (date_time = :before_date_time AND (COALESCE(batch_index, '.self::NULL_BATCH_ORDER.') < :before_batch_order OR (COALESCE(batch_index, '.self::NULL_BATCH_ORDER.') = :before_batch_order AND id <= :before_id))))';
        $bindings = [
            'tractor_id' => $tractorId,
            'start_of_day' => $startOfDay,
            'end_of_day' => $endOfDay,
            'high_water_id' => $highWaterId,
            'before_date_time' => (string) ($after['date_time'] ?? ''),
            'before_batch_order' => (int) ($after['batch_order'] ?? self::NULL_BATCH_ORDER),
            'before_id' => (int) ($after['id'] ?? 0),
        ];

        return array_reverse($this->runOrderedQuery('DESC', $condition, $bindings, $limit));
    }

    /** @return array<int,array<string,mixed>> */
    private function runOrderedQuery(string $direction, string $condition, array $bindings, int $limit): array
    {
        $hasBatchIndex = $this->hasGpsDataColumn('batch_index');
        $batchSelect = $hasBatchIndex ? ', batch_index' : '';
        $batchOrder = $hasBatchIndex ? 'COALESCE(batch_index, '.self::NULL_BATCH_ORDER.')' : (string) self::NULL_BATCH_ORDER;
        $condition = str_replace('COALESCE(batch_index, '.self::NULL_BATCH_ORDER.')', $batchOrder, $condition);
        $sql = 'SELECT id, coordinate, speed, status, directions, date_time'.$batchSelect
            .' FROM gps_data WHERE tractor_id = :tractor_id AND date_time >= :start_of_day AND date_time < :end_of_day AND id <= :high_water_id'
            .$condition
            .' ORDER BY date_time '.$direction.', '.$batchOrder.' '.$direction.', id '.$direction.' LIMIT :path_limit';
        $pdo = $this->getGpsReadPdo();
        $statement = $pdo->prepare($sql);
        foreach ($bindings as $name => $value) {
            $statement->bindValue(':'.$name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':path_limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $this->restoreBufferedQueryMode();

        return $rows;
    }

    private function hasGpsDataColumn(string $column): bool
    {
        if ($column === 'batch_index' && $this->hasBatchIndexColumn !== null) {
            return $this->hasBatchIndexColumn;
        }

        $database = (string) config('database.connections.mysql_gps_read.database');
        $row = $this->gpsReadSelectOne(
            'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$database, 'gps_data', $column],
        );
        $exists = ((int) ($row->c ?? 0)) > 0;
        if ($column === 'batch_index') {
            $this->hasBatchIndexColumn = $exists;
        }

        return $exists;
    }

    /** @param array<string,mixed> $row @return array{date_time:string,batch_order:int,id:int} */
    private function orderKey(array $row): array
    {
        $hasBatchIndex = array_key_exists('batch_index', $row) && $row['batch_index'] !== null;

        return [
            'date_time' => (string) ($row['date_time'] ?? ''),
            'batch_order' => $hasBatchIndex ? (int) $row['batch_index'] : self::NULL_BATCH_ORDER,
            'id' => (int) $row['id'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function formatPoint(array $row, int $segmentOffset): array
    {
        [$latitude, $longitude] = $this->parseCoordinate($row['coordinate'] ?? null);
        $dateTime = isset($row['date_time']) ? (string) $row['date_time'] : null;
        $stoppageSeconds = (int) ($row['stoppage_time_seconds'] ?? 0);

        return [
            'id' => (int) $row['id'],
            'latitude' => $latitude,
            'longitude' => $longitude,
            'speed' => (int) ($row['speed'] ?? 0),
            'status' => (int) ($row['status'] ?? 0),
            'is_starting_point' => false,
            'is_ending_point' => false,
            'is_stopped' => (bool) ($row['is_stopped'] ?? false),
            'directions' => is_string($row['directions'] ?? null)
                ? json_decode((string) $row['directions'], true)
                : ($row['directions'] ?? null),
            'stoppage_time' => $this->formatStoppage($stoppageSeconds),
            'timestamp' => $dateTime !== null && strlen($dateTime) >= 19
                ? substr($dateTime, 11, 8)
                : self::ZERO_STOPPAGE_TIME,
            'event_time' => $dateTime,
            'trajectory_classification' => $row['trajectory_classification'] ?? null,
            'segment_id' => (int) ($row['segment_id'] ?? 0) + $segmentOffset,
            'is_display_point' => true,
            'trajectory_distance_from_previous_meters' => isset($row['trajectory_distance_from_previous_meters'])
                ? (float) $row['trajectory_distance_from_previous_meters']
                : null,
            'trajectory_implied_speed_kmh' => isset($row['trajectory_implied_speed_kmh'])
                ? (float) $row['trajectory_implied_speed_kmh']
                : null,
        ];
    }

    private function formatStoppage(int $seconds): string
    {
        if ($seconds <= 0) {
            return self::ZERO_STOPPAGE_TIME;
        }

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
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

        return [(float) ($coordinate[0] ?? 0), (float) ($coordinate[1] ?? 0)];
    }

    private function resolvePathDateWindow(Carbon $date): array
    {
        $timezone = config('app.timezone', 'Asia/Tehran');
        $localStart = $date->copy()->setTimezone($timezone)->startOfDay();

        return [$localStart->format('Y-m-d H:i:s'), $localStart->copy()->addDay()->format('Y-m-d H:i:s')];
    }

    private function meta(int $highWaterId, int $rawCount, int $verifiedCount, int $rejectedCount, int $renderedCount, bool $hasMore, ?string $nextCursor): array
    {
        return [
            'contract' => 'gps-path-v2',
            'snapshot' => ['high_water_id' => $highWaterId],
            'raw_count' => $rawCount,
            'verified_count' => $verifiedCount,
            'rejected_count' => $rejectedCount,
            'rendered_count' => $renderedCount,
            'path_complete' => ! $hasMore,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
        ];
    }
}
