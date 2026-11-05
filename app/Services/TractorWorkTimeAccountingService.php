<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;

/**
 * Policy-owned tractor time accounting.
 *
 * The legacy `work_duration` field remains movement-only for compatibility.
 * This service emits explicit duty/overtime fields so callers cannot confuse
 * movement, engine-on stoppage, engine-off time, and unknown gaps.
 */
final class TractorWorkTimeAccountingService
{
    public const POLICY_VERSION = 'duty-overtime-v1';
    public const MAX_SUPPORTED_INTERVAL_SECONDS = 180;

    public function __construct(private ?IstartekEventResolver $eventResolver = null)
    {
        $this->eventResolver ??= new IstartekEventResolver();
    }

    /**
     * Load one civil day from the GPS read model and classify valid intervals.
     * No writes are performed by this service.
     *
     * @return array<string,mixed>
     */
    public function calculate(Tractor $tractor, Carbon $date): array
    {
        $timezone = config('app.timezone', 'Asia/Tehran');
        $dayStart = $date->copy()->setTimezone($timezone)->startOfDay();
        $duty = $this->resolveDutyWindow($tractor, $dayStart);
        $queryEnd = $dayStart->copy()->addDay();

        if ($duty['end']->greaterThan($queryEnd)) {
            $queryEnd = $duty['end'];
        }

        $rows = $this->eventResolver->resolveForWindow(
            (int) $tractor->id,
            $dayStart->format('Y-m-d H:i:s'),
            $queryEnd->format('Y-m-d H:i:s'),
        );

        return $this->calculateFromPoints(
            array_map(static fn (array $row): array => [
                'id' => $row['id'] ?? 0,
                'date_time' => $row['date_time'] ?? null,
                'coordinate' => $row['coordinate'] ?? null,
                'speed' => $row['speed'] ?? 0,
                'status' => $row['status'] ?? 0,
            ], $rows),
            $tractor,
            $dayStart,
        );
    }

    /**
     * Pure calculation entry point used by isolated tests and fixtures.
     *
     * @param array<int,array<string,mixed>> $points
     * @return array<string,mixed>
     */
    public function calculateFromPoints(array $points, Tractor $tractor, Carbon $date): array
    {
        $timezone = config('app.timezone', 'Asia/Tehran');
        $dayStart = $date->copy()->setTimezone($timezone)->startOfDay();
        $duty = $this->resolveDutyWindow($tractor, $dayStart);
        $valid = [];
        $invalid = 0;
        $duplicates = 0;
        $outOfOrder = 0;
        $seen = [];
        $lastInputTimestamp = null;

        foreach ($points as $index => $point) {
            $coordinate = $this->parseCoordinate($point['coordinate'] ?? null);
            $speed = is_numeric($point['speed'] ?? null) ? (float) $point['speed'] : null;
            $status = is_numeric($point['status'] ?? null) ? (int) $point['status'] : null;
            $rawTime = $point['date_time'] ?? null;

            if ($coordinate === null || $speed === null || $speed < 0 || $status === null
                || (! is_scalar($rawTime) && ! $rawTime instanceof \DateTimeInterface)) {
                $invalid++;
                continue;
            }

            try {
                $timestamp = $rawTime instanceof \DateTimeInterface
                    ? Carbon::instance($rawTime)->setTimezone($timezone)->timestamp
                    : Carbon::parse((string) $rawTime, $timezone)->timestamp;
            } catch (\Throwable) {
                $invalid++;
                continue;
            }

            if ($lastInputTimestamp !== null && $timestamp < $lastInputTimestamp) {
                $outOfOrder++;
            }
            $lastInputTimestamp = $timestamp;

            $key = implode('|', [
                $timestamp,
                sprintf('%.6f,%.6f', $coordinate[0], $coordinate[1]),
                $speed,
                $status,
            ]);

            if (isset($seen[$key])) {
                $duplicates++;
                continue;
            }
            $seen[$key] = true;

            $valid[] = [
                'id' => (int) ($point['id'] ?? $index),
                'timestamp' => $timestamp,
                'latitude' => $coordinate[0],
                'longitude' => $coordinate[1],
                'speed' => $speed,
                'status' => $status,
            ];
        }

        usort($valid, fn (array $left, array $right): int =>
            $left['timestamp'] <=> $right['timestamp'] ?: $left['id'] <=> $right['id']);

        $result = [
            'policy_version' => self::POLICY_VERSION,
            'timezone' => $timezone,
            'schedule_start' => $duty['start']->format('H:i:s'),
            'schedule_end' => $duty['end']->format('H:i:s'),
            'scheduled_duty_seconds' => max(0, $duty['end']->timestamp - $duty['start']->timestamp),
            'valid_gps_count' => count($valid),
            'invalid_gps_count' => $invalid,
            'duplicate_gps_count' => $duplicates,
            'out_of_order_count' => $outOfOrder,
            'unknown_gap_seconds' => 0,
            'unknown_gap_count' => 0,
            'unknown_state_seconds' => 0,
            'duty_moving_seconds' => 0,
            'duty_engine_on_stopped_seconds' => 0,
            'overtime_moving_seconds' => 0,
            'overtime_seconds' => 0,
            'outside_duty_engine_on_stopped_seconds' => 0,
            'engine_off_stopped_seconds' => 0,
            'duty_work_seconds' => 0,
            'total_countable_work_seconds' => 0,
            'moving_seconds' => 0,
            'stoppage_seconds' => 0,
            'stoppage_seconds_while_on' => 0,
            'stoppage_seconds_while_off' => 0,
            'stoppage_count' => 0,
            'distance_km' => 0.0,
            'first_valid_timestamp' => $valid[0]['timestamp'] ?? null,
            'last_valid_timestamp' => $valid !== [] ? $valid[array_key_last($valid)]['timestamp'] : null,
        ];

        $previous = null;
        $previousCategory = null;
        foreach ($valid as $current) {
            if ($previous === null) {
                $previous = $current;
                $previousCategory = $this->category($current);
                continue;
            }

            $duration = $current['timestamp'] - $previous['timestamp'];
            if ($duration <= 0) {
                $result['out_of_order_count']++;
                $previous = $current;
                $previousCategory = $this->category($current);
                continue;
            }

            if ($duration > self::MAX_SUPPORTED_INTERVAL_SECONDS) {
                $result['unknown_gap_seconds'] += $duration;
                $result['unknown_gap_count']++;
                $previous = $current;
                $previousCategory = $this->category($current);
                continue;
            }

            $category = $previousCategory ?? $this->category($previous);
            $dutySeconds = $this->overlapSeconds(
                $previous['timestamp'],
                $current['timestamp'],
                $duty['start']->timestamp,
                $duty['end']->timestamp,
            );
            $outsideDutySeconds = max(0, $duration - $dutySeconds);

            if ($category === 'MOVING_VALID') {
                $result['duty_moving_seconds'] += $dutySeconds;
                $result['overtime_moving_seconds'] += $outsideDutySeconds;
                $result['distance_km'] += $this->haversineKm($previous, $current);
            } elseif ($category === 'STOPPED_ENGINE_ON') {
                $result['duty_engine_on_stopped_seconds'] += $dutySeconds;
                $result['outside_duty_engine_on_stopped_seconds'] += $outsideDutySeconds;
                if ($duration >= 60 && $previousCategory !== 'STOPPED_ENGINE_ON') {
                    $result['stoppage_count']++;
                }
            } elseif ($category === 'STOPPED_ENGINE_OFF') {
                $result['engine_off_stopped_seconds'] += $duration;
            } elseif ($category === 'STOPPED_ENGINE_UNKNOWN') {
                $result['unknown_state_seconds'] += $duration;
            }

            if ($category === 'STOPPED_ENGINE_ON') {
                $result['stoppage_seconds_while_on'] += $duration;
            } elseif ($category === 'STOPPED_ENGINE_OFF') {
                $result['stoppage_seconds_while_off'] += $duration;
            }

            $previous = $current;
            $previousCategory = $this->category($current);
        }

        $result['moving_seconds'] = $result['duty_moving_seconds'] + $result['overtime_moving_seconds'];
        $result['stoppage_seconds'] = $result['stoppage_seconds_while_on'] + $result['stoppage_seconds_while_off'];
        $result['duty_work_seconds'] = $result['duty_moving_seconds'] + $result['duty_engine_on_stopped_seconds'];
        $result['overtime_seconds'] = $result['overtime_moving_seconds'];
        $result['total_countable_work_seconds'] = $result['duty_work_seconds'] + $result['overtime_seconds'];

        $result['durations'] = [
            'scheduled_duty' => $this->formatSeconds($result['scheduled_duty_seconds']),
            'duty_moving' => $this->formatSeconds($result['duty_moving_seconds']),
            'duty_engine_on_stopped' => $this->formatSeconds($result['duty_engine_on_stopped_seconds']),
            'duty_work' => $this->formatSeconds($result['duty_work_seconds']),
            'overtime' => $this->formatSeconds($result['overtime_seconds']),
            'total_countable_work' => $this->formatSeconds($result['total_countable_work_seconds']),
            'moving' => $this->formatSeconds($result['moving_seconds']),
            'stoppage' => $this->formatSeconds($result['stoppage_seconds']),
            'stoppage_while_on' => $this->formatSeconds($result['stoppage_seconds_while_on']),
            'stoppage_while_off' => $this->formatSeconds($result['stoppage_seconds_while_off']),
            'unknown_gap' => $this->formatSeconds($result['unknown_gap_seconds']),
            'unknown_state' => $this->formatSeconds($result['unknown_state_seconds']),
        ];

        $scheduled = max(0, $result['scheduled_duty_seconds']);
        $result['duty_fulfillment_percent'] = $scheduled > 0
            ? round($result['duty_work_seconds'] / $scheduled * 100, 2)
            : null;
        $result['total_work_ratio_percent'] = $scheduled > 0
            ? round($result['total_countable_work_seconds'] / $scheduled * 100, 2)
            : null;

        return $result;
    }

    /** @return array{start: Carbon, end: Carbon} */
    private function resolveDutyWindow(Tractor $tractor, Carbon $dayStart): array
    {
        $start = $dayStart->copy()->setTimeFromTimeString($tractor->start_work_time ?: '00:00:00');
        $end = $dayStart->copy()->setTimeFromTimeString($tractor->end_work_time ?: '00:00:00');

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return ['start' => $start, 'end' => $end];
    }

    private function category(array $point): string
    {
        if ($point['status'] === 1 && $point['speed'] > 0) {
            return 'MOVING_VALID';
        }
        if ($point['status'] === 1 && $point['speed'] === 0.0) {
            return 'STOPPED_ENGINE_ON';
        }
        if ($point['status'] === 0 && $point['speed'] === 0.0) {
            return 'STOPPED_ENGINE_OFF';
        }

        return 'STOPPED_ENGINE_UNKNOWN';
    }

    private function overlapSeconds(int $start, int $end, int $windowStart, int $windowEnd): int
    {
        return max(0, min($end, $windowEnd) - max($start, $windowStart));
    }

    /** @return array{0: float, 1: float}|null */
    private function parseCoordinate(mixed $coordinate): ?array
    {
        if (is_string($coordinate)) {
            $decoded = json_decode($coordinate, true);
            $coordinate = is_array($decoded) ? $decoded : explode(',', $coordinate, 2);
        }

        if (!is_array($coordinate) || count($coordinate) < 2 || !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) {
            return null;
        }

        $latitude = (float) $coordinate[0];
        $longitude = (float) $coordinate[1];
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return [$latitude, $longitude];
    }

    private function haversineKm(array $left, array $right): float
    {
        $earthRadius = 6371.0;
        $lat1 = deg2rad($left['latitude']);
        $lat2 = deg2rad($right['latitude']);
        $dLat = deg2rad($right['latitude'] - $left['latitude']);
        $dLon = deg2rad($right['longitude'] - $left['longitude']);
        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * $earthRadius * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }

    private function formatSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
