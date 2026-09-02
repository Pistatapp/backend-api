<?php

namespace Tests\Unit\Services;

use App\Services\DeviceTrajectoryProfileResolver;
use App\Services\TractorTrajectoryService;
use Carbon\Carbon;
use Tests\TestCase;

class TractorTrajectoryServiceTest extends TestCase
{
    private TractorTrajectoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TractorTrajectoryService::class);
        config([
            'app.timezone' => 'Asia/Tehran',
            'trajectory.stationary.minimum_window_seconds' => 60,
            'trajectory.stationary.minimum_points' => 3,
            'trajectory.stationary.window_seconds' => 180,
            'trajectory.stationary.maximum_window_points' => 48,
            'trajectory.stationary.low_speed_kmh' => 2.0,
            'trajectory.movement.minimum_progression_points' => 3,
            'trajectory.movement.minimum_net_displacement_multiplier' => 1.25,
            'trajectory.movement.minimum_directional_consistency' => 0.55,
        ]);
    }

    public function test_sorts_are_deterministic_when_rows_are_inserted_out_of_order(): void
    {
        $result = $this->service->analyze([
            $this->row(3, '2026-09-02 08:00:20', 35.002),
            $this->row(1, '2026-09-02 08:00:00', 35.000),
            $this->row(2, '2026-09-02 08:00:10', 35.001),
        ], $this->profile());

        $this->assertSame([1, 2, 3], array_column($result['rows'], 'id'));
    }

    public function test_same_timestamp_uses_batch_index_before_id(): void
    {
        $result = $this->service->analyze([
            $this->row(20, '2026-09-02 08:00:00', 5) + ['batch_index' => 2],
            $this->row(10, '2026-09-02 08:00:00', 5) + ['batch_index' => 1],
        ], $this->profile());

        $this->assertSame([10, 20], array_column($result['rows'], 'id'));
    }

    public function test_parked_jitter_becomes_one_stationary_cluster_and_not_movement(): void
    {
        $rows = [];
        $coords = [[35.0, 51.0], [35.00005, 51.00004], [34.99996, 51.00006], [35.00004, 50.99995], [35.0, 51.00003]];
        foreach ($coords as $i => $coordinate) {
            $rows[] = $this->row($i + 1, Carbon::parse('2026-09-02 08:00:00')->addSeconds($i * 20)->toDateTimeString(), 0, $coordinate);
        }

        $result = $this->service->analyze($rows, $this->profile());

        $this->assertSame(1, $result['metrics']['stationary_cluster_count']);
        $this->assertEquals(0.0, $result['metrics']['operational_movement_distance_meters']);
        $this->assertCount(1, array_filter($result['rows'], fn ($row) => $row['is_display_point']));
        $this->assertCount(5, $result['rows']);
    }

    public function test_speed_zero_progression_is_moving(): void
    {
        $rows = [];
        foreach ([0, 20, 40, 60] as $i => $meters) {
            $rows[] = $this->row($i + 1, sprintf('2026-09-02 09:00:%02d', $i * 20), 0, [35.0 + ($meters / 111_000), 51.0]);
        }

        $result = $this->service->analyze($rows, $this->profile());

        $this->assertContains(TractorTrajectoryService::MOVING, array_column($result['rows'], 'trajectory_classification'));
        $this->assertGreaterThan(20, $result['metrics']['operational_movement_distance_meters']);
    }

    public function test_single_jitter_excursion_is_not_sustained_movement(): void
    {
        $result = $this->service->analyze([
            $this->row(1, '2026-09-02 10:00:00', 0, [35.0, 51.0]),
            $this->row(2, '2026-09-02 10:00:20', 0, [35.001, 51.001]),
            $this->row(3, '2026-09-02 10:00:40', 0, [35.0, 51.0]),
        ], $this->profile());

        $this->assertNotContains(TractorTrajectoryService::MOVING, array_column($result['rows'], 'trajectory_classification'));
    }

    public function test_a_to_b_to_a_point_is_transient_spike_and_raw_row_is_retained(): void
    {
        $result = $this->service->analyze([
            $this->row(1, '2026-09-02 11:00:00', 0, [35.0, 51.0]),
            $this->row(2, '2026-09-02 11:00:10', 0, [35.003, 51.003]),
            $this->row(3, '2026-09-02 11:00:20', 0, [35.0, 51.0]),
        ], $this->profile());

        $this->assertCount(3, $result['rows']);
        $this->assertSame(TractorTrajectoryService::TRANSIENT_SPIKE, $result['rows'][1]['trajectory_classification']);
        $this->assertFalse($result['rows'][1]['is_display_point']);
    }

    public function test_invalid_coordinate_is_retained_but_not_displayed(): void
    {
        $result = $this->service->analyze([
            $this->row(1, '2026-09-02 12:00:00', 10, [35.0, 51.0]),
            $this->row(2, '2026-09-02 12:00:10', 10, [0.0, 0.0]),
            $this->row(3, '2026-09-02 12:00:20', 10, [35.001, 51.001]),
        ], $this->profile());

        $this->assertSame(TractorTrajectoryService::INVALID_COORDINATE, $result['rows'][1]['trajectory_classification']);
        $this->assertFalse($result['rows'][1]['is_display_point']);
        $this->assertCount(3, $result['rows']);
        $this->assertNotSame($result['rows'][0]['segment_id'], $result['rows'][2]['segment_id']);
    }

    public function test_missing_data_gap_starts_a_new_segment(): void
    {
        $result = $this->service->analyze([
            $this->row(1, '2026-09-02 13:00:00', 10),
            $this->row(2, '2026-09-02 13:20:01', 10, [35.001, 51.001]),
        ], $this->profile());

        $this->assertSame(0, $result['rows'][0]['segment_id']);
        $this->assertSame(1, $result['rows'][1]['segment_id']);
    }

    public function test_profile_resolver_has_centralized_device_classes(): void
    {
        $this->assertSame('UNKNOWN', app(DeviceTrajectoryProfileResolver::class)->resolve(null)['name']);
        $this->assertArrayHasKey('noise_radius_meters', app(DeviceTrajectoryProfileResolver::class)->resolve(null));
    }

    private function profile(): array
    {
        return ['name' => 'TEST', 'noise_radius_meters' => 15.0, 'max_plausible_speed_kmh' => 45.0, 'gap_seconds' => 600];
    }

    private function row(int $id, string $dateTime, int $speed, array $coordinate = [35.0, 51.0]): array
    {
        return ['id' => $id, 'coordinate' => json_encode($coordinate), 'speed' => $speed, 'status' => 1, 'directions' => '[]', 'date_time' => $dateTime];
    }
}
