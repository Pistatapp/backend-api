<?php

namespace App\Services;

use App\Models\Tractor;
use App\Traits\GpsReadConnection;
use Carbon\Carbon;

/**
 * Resolves iStartek rows into one logical event stream without rewriting the
 * append-only GPS tables.  The three source cohorts deliberately use their
 * own evidence-backed time window:
 *
 *  - Sidecar rows: event_time_utc;
 *  - ist-* rows without a Sidecar: stored Tehran civil date_time;
 *  - legacy/no-ID rows: stored UTC-naive date_time.
 *
 * A non-Sidecar row is render-suppressed only when its effective UTC second
 * and six-decimal coordinate match a verified Sidecar row.  Rows with the
 * same second but a different coordinate remain independent events.
 */
class IstartekEventResolver
{
    use GpsReadConnection;

    private const TIMEZONE = 'Asia/Tehran';

    /** @var array<string,bool>|null */
    private ?array $istartekImeis = null;

    /** @var array<string,int> */
    private array $lastStats = [
        'canonical' => 0,
        'ist_no_sidecar' => 0,
        'legacy_utc_naive' => 0,
        'superseded' => 0,
        'unknown' => 0,
    ];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function resolveForTractorDay(Tractor $tractor, Carbon $date): array
    {
        [$start, $end] = $tractor->getWorkingWindow($date);

        return $this->resolveForWindow(
            (int) $tractor->id,
            (string) $start,
            (string) $end,
        );
    }

    /**
     * Resolve a Tehran-civil window. The returned rows retain the legacy
     * fields and add only internal/additive resolver metadata.
     *
     * @return array<int,array<string,mixed>>
     */
    public function resolveForWindow(int $tractorId, string $start, string $end): array
    {
        $localStart = Carbon::parse($start, self::TIMEZONE);
        $localEnd = Carbon::parse($end, self::TIMEZONE);
        $utcStart = $localStart->copy()->utc()->format('Y-m-d H:i:s');
        $utcEnd = $localEnd->copy()->utc()->format('Y-m-d H:i:s');
        $civilStart = $localStart->format('Y-m-d H:i:s');
        $civilEnd = $localEnd->format('Y-m-d H:i:s');

        $allRows = $this->readCivilRows($tractorId, $civilStart, $civilEnd);
        $imeis = $this->istartekImeis();
        if ($imeis === []) {
            $this->lastStats = [
                'canonical' => 0,
                'ist_no_sidecar' => 0,
                'legacy_utc_naive' => 0,
                'superseded' => 0,
                'unknown' => 0,
            ];

            return $allRows;
        }

        $canonical = $this->readCanonicalRows($tractorId, $utcStart, $utcEnd);
        $istNoSidecar = $this->readIstarCivilRows($tractorId, $civilStart, $civilEnd, $imeis);
        $legacy = $this->readIstarLegacyRows($tractorId, $utcStart, $utcEnd, $imeis);

        // The fallback civil query is retained byte-for-byte for non-iStartek
        // devices. Only rows whose IMEI is evidenced as iStartek are replaced
        // by the cohort-aware union below.
        $nonIstarRows = array_values(array_filter(
            $allRows,
            fn (array $row): bool => ! isset($imeis[(string) ($row['imei'] ?? '')]),
        ));

        if ($canonical === [] && $istNoSidecar === [] && $legacy === []) {
            $this->lastStats = [
                'canonical' => 0,
                'ist_no_sidecar' => 0,
                'legacy_utc_naive' => 0,
                'superseded' => 0,
                'unknown' => 0,
            ];

            return $allRows;
        }

        $canonicalKeys = [];
        foreach ($canonical as $row) {
            $canonicalKeys[$this->logicalKey($row)] = true;
        }

        $resolved = $canonical;
        $superseded = 0;
        $supersededIds = $this->readSupersededIds(array_merge($istNoSidecar, $legacy));
        foreach (array_merge($istNoSidecar, $legacy) as $row) {
            if (isset($supersededIds[(int) ($row['id'] ?? 0)])
                || isset($canonicalKeys[$this->logicalKey($row)])) {
                $superseded++;
                continue;
            }
            $resolved[] = $row;
        }

        foreach ($resolved as &$row) {
            $row['superseded'] = false;
        }
        unset($row);

        usort($resolved, fn (array $left, array $right): int => $this->compareRows($left, $right));

        $this->lastStats = [
            'canonical' => count($canonical),
            'ist_no_sidecar' => count($istNoSidecar),
            'legacy_utc_naive' => count($legacy),
            'superseded' => $superseded,
            'unknown' => 0,
        ];

        $merged = array_merge($nonIstarRows, $resolved);
        usort($merged, fn (array $left, array $right): int => $this->compareRows(
            $this->withFallbackEffectiveUtc($left),
            $this->withFallbackEffectiveUtc($right),
        ));

        return $merged;
    }

    /** @return array<string,int> */
    public function lastStats(): array
    {
        return $this->lastStats;
    }

    /** @param array<string,mixed> $row */
    public function isIstartekRow(array $row): bool
    {
        $protocol = strtolower(trim((string) ($row['source_protocol'] ?? '')));
        $eventId = strtolower(trim((string) ($row['event_id'] ?? '')));

        return $protocol === 'istartek'
            || str_starts_with($eventId, 'ist-')
            || isset($this->istartekImeis()[(string) ($row['imei'] ?? '')]);
    }

    public function isIstartekImei(?string $imei): bool
    {
        $imei = trim((string) $imei);

        return $imei !== '' && isset($this->istartekImeis()[$imei]);
    }

    /** @return array<int,array<string,mixed>> */
    private function readCivilRows(int $tractorId, string $start, string $end): array
    {
        $rows = $this->gpsReadSelect(
            'SELECT id, coordinate, speed, status, directions, date_time, imei, event_id, batch_index
             FROM gps_data
             WHERE tractor_id = ? AND date_time >= ? AND date_time < ?
             ORDER BY date_time ASC, id ASC',
            [$tractorId, $start, $end],
        );

        return array_map(static fn (object $row): array => (array) $row, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function readCanonicalRows(int $tractorId, string $utcStart, string $utcEnd): array
    {
        $rows = $this->gpsReadSelect(
            'SELECT g.id, g.coordinate, g.speed, g.status, g.directions, g.date_time,
                    g.imei, COALESCE(p.event_id, g.event_id) AS event_id, g.batch_index,
                    p.event_time_utc, p.event_time_tehran, p.event_date_jalali,
                    p.timezone, p.source_protocol, p.time_provenance,
                    p.other_original_gps_fields
             FROM gps_event_time_provenance p
             INNER JOIN gps_data g
                ON g.id = p.gps_data_id AND g.date_time = p.gps_date_time
             WHERE g.tractor_id = ? AND p.source_protocol = ?
               AND p.verification_status = ?
               AND p.event_time_utc >= ? AND p.event_time_utc < ?
             ORDER BY p.event_time_utc ASC, g.id ASC',
            [$tractorId, 'istartek', 'verified', $utcStart, $utcEnd],
        );

        return array_map(function (object $row): array {
            $value = (array) $row;
            $value['effective_utc'] = substr((string) $value['event_time_utc'], 0, 19);
            $value['time_basis'] = 'sidecar_utc';
            $value['source_class'] = 'canonical';
            return $value;
        }, $rows);
    }

    /**
     * @param array<string,bool> $imeis
     * @return array<int,array<string,mixed>>
     */
    private function readIstarCivilRows(int $tractorId, string $civilStart, string $civilEnd, array $imeis): array
    {
        if ($imeis === []) return [];
        [$in, $bindings] = $this->imeiBindings($imeis);
        $bindings = array_merge([$tractorId], $bindings, [$civilStart, $civilEnd]);
        $rows = $this->gpsReadSelect(
            'SELECT g.id, g.coordinate, g.speed, g.status, g.directions, g.date_time,
                    g.imei, g.event_id, g.batch_index
             FROM gps_data g
             WHERE g.tractor_id = ? AND g.imei IN ('.$in.')
               AND g.event_id LIKE \'ist-%\'
               AND g.date_time >= ? AND g.date_time < ?
               AND NOT EXISTS (
                   SELECT 1 FROM gps_event_time_provenance p
                   WHERE p.gps_data_id = g.id AND p.gps_date_time = g.date_time
                     AND p.source_protocol = \'istartek\'
               )
             ORDER BY g.date_time ASC, g.id ASC',
            $bindings,
        );

        return array_map(function (object $row): array {
            $value = (array) $row;
            $effective = Carbon::parse(substr((string) $value['date_time'], 0, 19), self::TIMEZONE)
                ->utc()->format('Y-m-d H:i:s');
            $value['effective_utc'] = $effective;
            $value['time_basis'] = 'ist_tehran_civil_proven';
            $value['source_class'] = 'ist_no_sidecar';
            return $value;
        }, $rows);
    }

    /**
     * @param array<string,bool> $imeis
     * @return array<int,array<string,mixed>>
     */
    private function readIstarLegacyRows(int $tractorId, string $utcStart, string $utcEnd, array $imeis): array
    {
        if ($imeis === []) return [];
        [$in, $bindings] = $this->imeiBindings($imeis);
        $bindings = array_merge([$tractorId], $bindings, [$utcStart, $utcEnd]);
        $rows = $this->gpsReadSelect(
            'SELECT g.id, g.coordinate, g.speed, g.status, g.directions, g.date_time,
                    g.imei, g.event_id, g.batch_index
             FROM gps_data g
             WHERE g.tractor_id = ? AND g.imei IN ('.$in.')
               AND (g.event_id IS NULL OR g.event_id NOT LIKE \'ist-%\')
               AND g.date_time >= ? AND g.date_time < ?
               AND NOT EXISTS (
                   SELECT 1 FROM gps_event_time_provenance p
                   WHERE p.gps_data_id = g.id AND p.gps_date_time = g.date_time
                     AND p.source_protocol = \'istartek\'
               )
             ORDER BY g.date_time ASC, g.id ASC',
            $bindings,
        );

        return array_map(function (object $row): array {
            $value = (array) $row;
            $value['effective_utc'] = substr((string) $value['date_time'], 0, 19);
            $value['time_basis'] = 'legacy_utc_naive_proven';
            $value['source_class'] = 'legacy_utc_naive';
            $value['_legacy_date_time'] = $value['date_time'];
            $value['date_time'] = Carbon::parse($value['effective_utc'], 'UTC')
                ->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s');
            return $value;
        }, $rows);
    }

    /** @param array<string,bool> $imeis @return array{0:string,1:array<int,string>} */
    private function imeiBindings(array $imeis): array
    {
        $values = array_keys($imeis);

        return [implode(',', array_fill(0, count($values), '?')), $values];
    }

    /** @return array<string,bool> */
    private function istartekImeis(): array
    {
        if ($this->istartekImeis !== null) return $this->istartekImeis;

        $rows = $this->gpsReadSelect(
            'SELECT DISTINCT imei FROM gps_event_time_provenance
             WHERE source_protocol = ? AND imei <> \'\'
             UNION
             SELECT DISTINCT imei FROM gps_data
             WHERE event_id LIKE \'ist-%\' AND imei <> \'\'',
            ['istartek'],
        );
        $this->istartekImeis = [];
        foreach ($rows as $row) {
            $imei = trim((string) ($row->imei ?? ''));
            if ($imei !== '') $this->istartekImeis[$imei] = true;
        }

        return $this->istartekImeis;
    }

    /** @param array<string,mixed> $row */
    private function logicalKey(array $row): string
    {
        return substr((string) ($row['effective_utc'] ?? ''), 0, 19)
            .'|'.$this->coordinateKey($row['coordinate'] ?? null);
    }

    private function coordinateKey(mixed $coordinate): string
    {
        $value = is_string($coordinate) ? json_decode($coordinate, true) : $coordinate;
        if (! is_array($value) || ! isset($value[0], $value[1])) return 'invalid';

        return sprintf('%.6f|%.6f', (float) $value[0], (float) $value[1]);
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,bool> */
    private function readSupersededIds(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows,
        ))));
        if ($ids === []) return [];

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $records = $this->gpsReadSelect(
                'SELECT gps_data_id FROM gps_data_superseded WHERE gps_data_id IN ('.$placeholders.')',
                $ids,
            );
        } catch (\Throwable) {
            // Additive table is optional during rolling deploy; fail open and
            // retain the exact Sidecar/Raw-WAL key proof above.
            return [];
        }

        $result = [];
        foreach ($records as $record) {
            $result[(int) ($record->gps_data_id ?? 0)] = true;
        }

        return $result;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function compareRows(array $left, array $right): int
    {
        $time = strcmp((string) ($left['effective_utc'] ?? ''), (string) ($right['effective_utc'] ?? ''));
        if ($time !== 0) return $time;

        $leftBatch = $left['batch_index'] ?? null;
        $rightBatch = $right['batch_index'] ?? null;
        if ($leftBatch === null && $rightBatch !== null) return 1;
        if ($leftBatch !== null && $rightBatch === null) return -1;
        if ($leftBatch !== null && $rightBatch !== null && (int) $leftBatch !== (int) $rightBatch) {
            return (int) $leftBatch <=> (int) $rightBatch;
        }

        return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function withFallbackEffectiveUtc(array $row): array
    {
        if (isset($row['effective_utc'])) return $row;

        try {
            $row['effective_utc'] = Carbon::parse(
                substr((string) ($row['date_time'] ?? ''), 0, 19),
                self::TIMEZONE,
            )->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $row['effective_utc'] = (string) ($row['date_time'] ?? '');
        }

        return $row;
    }
}
