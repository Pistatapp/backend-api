<?php

namespace App\Services;

use App\Models\Tractor;
use App\Traits\GpsReadConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TractorPathStreamService
{
    use GpsReadConnection;

    private const ZERO_STOPPAGE_TIME = '00:00:00';

    private ?bool $hasBatchIndexColumn = null;

    public function __construct(
        private TractorTrajectoryService $trajectoryService,
        private DeviceTrajectoryProfileResolver $profileResolver,
    ) {}

    /** Return one Tehran civil day; all trajectory classification is request-time. */
    public function getTractorPath(Tractor $tractor, Carbon $date, bool $enablePathCorrection = true)
    {
        try {
            [$startOfDay, $endOfDay] = $this->resolvePathDateWindow($date);
            return response()->streamJson($this->streamPathPointsRaw($tractor, $startOfDay, $endOfDay));
        } catch (\Exception $e) {
            Log::error('Failed to get tractor path (streamed)', [
                'tractor_id' => $tractor->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);
            return response()->streamJson(new \EmptyIterator());
        }
    }

    /** @return array{0:string,1:string} */
    private function resolvePathDateWindow(Carbon $date): array
    {
        $timezone = config('app.timezone', 'Asia/Tehran');
        $localStart = $date->copy()->setTimezone($timezone)->startOfDay();
        return [$localStart->format('Y-m-d H:i:s'), $localStart->copy()->addDay()->format('Y-m-d H:i:s')];
    }

    /** @return \Generator<int,array<string,mixed>> */
    private function streamPathPointsRaw(Tractor $tractor, string $startOfDay, string $endOfDay): \Generator
    {
        $pdo = $this->getGpsReadPdo();
        $hasBatchIndex = $this->hasGpsDataColumn('batch_index');
        $batchIndexSelect = $hasBatchIndex ? ', batch_index' : '';
        $orderBy = $hasBatchIndex
            ? 'ORDER BY date_time ASC, (batch_index IS NULL) ASC, batch_index ASC, id ASC'
            : 'ORDER BY date_time ASC, id ASC';

        $stmt = $pdo->prepare('
            SELECT id, coordinate, speed, status, directions, date_time'.$batchIndexSelect.'
            FROM gps_data
            WHERE tractor_id = ? AND date_time >= ? AND date_time < ?
            '.$orderBy.'
        ');
        $stmt->execute([$tractor->id, $startOfDay, $endOfDay]);
        $rawRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->restoreBufferedQueryMode();
        $rawRows = $this->collapseLogicalDuplicateRows($rawRows);
        if ($rawRows === []) return;

        $analysis = $this->trajectoryService->analyze($rawRows, $this->profileResolver->resolve($tractor));
        foreach ($analysis['rows'] as $row) {
            yield $this->formatPointFromRow(
                $row,
                (bool) ($row['is_starting_point'] ?? false),
                (bool) ($row['is_ending_point'] ?? false),
                ($row['trajectory_classification'] ?? null) === TractorTrajectoryService::STATIONARY,
                (int) ($row['stoppage_time_seconds'] ?? 0),
            );
        }
    }

    private function hasGpsDataColumn(string $column): bool
    {
        if ($this->hasBatchIndexColumn !== null && $column === 'batch_index') return $this->hasBatchIndexColumn;
        try {
            $database = (string) config('database.connections.mysql_gps_read.database');
            $row = $this->gpsReadSelectOne(
                'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                [$database, 'gps_data', $column],
            );
            $exists = ((int) ($row->c ?? 0)) > 0;
        } catch (\Throwable) {
            $exists = false;
        }
        if ($column === 'batch_index') $this->hasBatchIndexColumn = $exists;
        return $exists;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function collapseLogicalDuplicateRows(array $rows): array
    {
        $seen = [];
        $collapsed = [];
        foreach ($rows as $row) {
            $key = (string) ($row['date_time'] ?? '').'|'.$this->coordinateDedupeKey($row['coordinate'] ?? null);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $collapsed[] = $row;
        }
        return $collapsed;
    }

    private function coordinateDedupeKey(mixed $coordinate): string
    {
        [$lat, $lon] = $this->parseCoordinate($coordinate);
        return sprintf('%.6f,%.6f', $lat, $lon);
    }

    /** @return array{0:float,1:float} */
    private function parseCoordinate(mixed $coordinate): array
    {
        if (is_string($coordinate)) {
            $trimmed = trim($coordinate);
            $coordinate = str_starts_with($trimmed, '[') ? json_decode($trimmed, true) : explode(',', $trimmed, 2);
        }
        return [(float) ($coordinate[0] ?? 0), (float) ($coordinate[1] ?? 0)];
    }

    private function formatPointFromRow(array $row, bool $isStartingPoint, bool $isEndingPoint, bool $isStopped, int $stoppageTime): array
    {
        return $this->formatPointArray(
            (int) $row['id'], $row['coordinate'], (int) $row['speed'], (int) $row['status'], $row['directions'], $row['date_time'],
            $isStartingPoint, $isEndingPoint, $isStopped, $stoppageTime,
            $row['trajectory_classification'] ?? null,
            isset($row['segment_id']) ? (int) $row['segment_id'] : null,
            $row['is_display_point'] ?? true,
            isset($row['trajectory_distance_from_previous_meters']) ? (float) $row['trajectory_distance_from_previous_meters'] : null,
            isset($row['trajectory_implied_speed_kmh']) ? (float) $row['trajectory_implied_speed_kmh'] : null,
        );
    }

    private function formatPointArray(
        int $id, mixed $coordinate, int $speed, int $status, mixed $directions, ?string $dateTime,
        bool $isStartingPoint, bool $isEndingPoint, bool $isStopped, int $stoppageTime,
        ?string $trajectoryClassification = null, ?int $segmentId = null, bool $isDisplayPoint = true,
        ?float $trajectoryDistance = null, ?float $trajectoryImpliedSpeed = null,
    ): array {
        [$lat, $lon] = $this->parseCoordinate($coordinate);
        $parsedDirections = is_string($directions) ? json_decode($directions, true) : $directions;
        $timestamp = ($dateTime && strlen($dateTime) >= 19) ? substr($dateTime, 11, 8) : self::ZERO_STOPPAGE_TIME;
        $formattedStoppage = $stoppageTime === 0
            ? self::ZERO_STOPPAGE_TIME
            : sprintf('%02d:%02d:%02d', intdiv($stoppageTime, 3600), intdiv($stoppageTime % 3600, 60), $stoppageTime % 60);
        return [
            'id' => $id, 'latitude' => $lat, 'longitude' => $lon, 'speed' => $speed, 'status' => $status,
            'is_starting_point' => $isStartingPoint, 'is_ending_point' => $isEndingPoint, 'is_stopped' => $isStopped,
            'directions' => $parsedDirections, 'stoppage_time' => $formattedStoppage, 'timestamp' => $timestamp,
            'event_time' => $dateTime, 'trajectory_classification' => $trajectoryClassification, 'segment_id' => $segmentId,
            'is_display_point' => $isDisplayPoint, 'trajectory_distance_from_previous_meters' => $trajectoryDistance,
            'trajectory_implied_speed_kmh' => $trajectoryImpliedSpeed,
        ];
    }
}
