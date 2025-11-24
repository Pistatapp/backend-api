<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Encapsulates common GPS path-correction algorithms:
 *  - Speed/status spike smoothing via a 3-point window
 *  - Streaming constant-velocity Kalman-style (alpha–beta) trajectory smoothing
 *  - Simple distance/speed-based outlier gating on coordinates
 *
 * All methods are streaming-friendly and operate on \Traversable inputs.
 */
class GpsPathCorrectionService
{
    /**
     * Alpha–beta filter gains for coordinate smoothing.
     *
     * Tuned for tractor-scale speeds:
     *  - alpha: position correction factor
     *  - beta:  velocity correction factor
     */
    private float $alpha = 0.35;
    private float $beta = 0.12;

    /**
     * Maximum plausible speed (km/h) before treating a position jump as an outlier.
     * Used to gate impossible coordinate jumps in the Kalman smoother.
     */
    private float $maxGateSpeedKmh = 70.0;

    /**
     * Stream-based speed/status smoothing: correct single-point anomalies using a 3-point window without loading all rows.
     *
     * Rules:
     *  - If a single movement point is surrounded by stoppages, treat it as stoppage (speed = 0).
     *  - If a single stoppage point is surrounded by movements, interpolate its speed from neighbors.
     *
     * @param \Traversable $points
     * @return \Generator
     */
    public function smoothSpeedStatusStream($points): \Generator
    {
        $buffer = [];
        foreach ($points as $p) {
            $buffer[] = $p;
            if (count($buffer) < 3) {
                continue;
            }

            [$prev, $curr, $next] = $buffer;

            $prevSpeed = (float) $prev->speed;
            $nextSpeed = (float) $next->speed;
            $currSpeed = (float) $curr->speed;

            $prevStatus = (int) $prev->status;
            $nextStatus = (int) $next->status;
            $currStatus = (int) $curr->status;

            $prevIsMovement = ($prevStatus === 1 && $prevSpeed > 0);
            $nextIsMovement = ($nextStatus === 1 && $nextSpeed > 0);
            $prevIsStoppage = ($prevSpeed == 0);
            $nextIsStoppage = ($nextSpeed == 0);
            $currIsMovement = ($currStatus === 1 && $currSpeed > 0);
            $currIsStoppage = ($currSpeed == 0);

            if ($currIsMovement && $prevIsStoppage && $nextIsStoppage) {
                // Isolated movement spike between stoppages → treat as stoppage
                $curr->speed = 0;
            } elseif ($currIsStoppage && $prevIsMovement && $nextIsMovement) {
                // Isolated stoppage between movements → interpolate speed
                $avgSpeed = ($prevSpeed + $nextSpeed) / 2;
                $curr->speed = $avgSpeed > 0 ? $avgSpeed : 1;
            }

            yield $curr;

            array_shift($buffer);
        }

        // Flush remaining points in buffer as-is
        if (count($buffer) === 2) {
            yield $buffer[0];
            yield $buffer[1];
        } elseif (count($buffer) === 1) {
            yield $buffer[0];
        }
    }

    /**
     * Streaming Kalman-style (alpha–beta) filter on coordinates with simple outlier gating.
     *
     * State (per axis):
     *  - position (deg)
     *  - velocity (deg/sec)
     *
     * Measurement:
     *  - raw GPS position (deg)
     *
     * Outlier gating:
     *  - If the implied speed between the predicted position and measurement exceeds maxGateSpeedKmh,
     *    the measurement is treated as an outlier and ignored for this update.
     *
     * @param \Traversable $points Stream of GPS points in chronological order
     * @return \Generator
     */
    public function kalmanSmoothCoordinatesStream($points): \Generator
    {
        $state = null;        // ['lat', 'lon', 'v_lat', 'v_lon']
        $prevTimestamp = null;

        foreach ($points as $point) {
            // Normalize timestamp to Carbon
            $ts = $point->date_time ?? null;
            if ($ts instanceof Carbon) {
                $currentTime = $ts;
            } elseif (is_string($ts)) {
                $currentTime = Carbon::parse($ts);
                $point->date_time = $currentTime;
            } else {
                $currentTime = Carbon::now();
                $point->date_time = $currentTime;
            }

            // Parse coordinate to numeric [lat, lon]
            $lat = 0.0;
            $lon = 0.0;
            $coord = $point->coordinate ?? null;

            if (is_string($coord)) {
                // Try JSON first (e.g. "[lat,lon]"), fallback to "lat,lon"
                $decoded = json_decode($coord, true);
                if (is_array($decoded)) {
                    $lat = isset($decoded[0]) ? (float) $decoded[0] : 0.0;
                    $lon = isset($decoded[1]) ? (float) $decoded[1] : 0.0;
                } else {
                    $parts = array_map('floatval', explode(',', $coord));
                    $lat = $parts[0] ?? 0.0;
                    $lon = $parts[1] ?? 0.0;
                }
            } elseif (is_array($coord)) {
                $lat = isset($coord[0]) ? (float) $coord[0] : 0.0;
                $lon = isset($coord[1]) ? (float) $coord[1] : 0.0;
            }

            // Initialize filter state on first valid point
            if ($state === null) {
                $state = [
                    'lat'   => $lat,
                    'lon'   => $lon,
                    'v_lat' => 0.0,
                    'v_lon' => 0.0,
                ];
                $prevTimestamp = $currentTime->timestamp;

                // Store filtered coordinate as array; downstream formatters already support arrays
                $point->coordinate = [$state['lat'], $state['lon']];
                yield $point;
                continue;
            }

            $currentTs = $currentTime->timestamp;
            $dt = max(0.1, $currentTs - $prevTimestamp); // seconds, avoid zero

            // --- Prediction step (constant-velocity model) ---
            $predLat = $state['lat'] + $state['v_lat'] * $dt;
            $predLon = $state['lon'] + $state['v_lon'] * $dt;

            // --- Outlier gating on measurement ---
            $measurementLat = $lat;
            $measurementLon = $lon;

            // If measurement is obviously invalid (0,0), fall back to prediction
            if ($measurementLat == 0.0 && $measurementLon == 0.0) {
                $measurementLat = $predLat;
                $measurementLon = $predLon;
            }

            // Use approximate geodesic distance in km if helper is available
            if (function_exists('calculate_distance')) {
                $distanceKm = calculate_distance(
                    [$predLat, $predLon],
                    [$measurementLat, $measurementLon]
                );
                $speedKmh = $dt > 0 ? ($distanceKm * 3600 / $dt) : 0.0;

                if ($speedKmh > $this->maxGateSpeedKmh) {
                    // Measurement implies impossible speed → treat as outlier and ignore for this update
                    $measurementLat = $predLat;
                    $measurementLon = $predLon;
                }
            }

            // --- Update step (alpha–beta) ---
            // Innovation (residual) between measurement and prediction
            $resLat = $measurementLat - $predLat;
            $resLon = $measurementLon - $predLon;

            // Position correction
            $newLat = $predLat + $this->alpha * $resLat;
            $newLon = $predLon + $this->beta * $resLon;

            // Velocity correction
            $newVLat = $state['v_lat'] + ($this->beta / $dt) * $resLat;
            $newVLon = $state['v_lon'] + ($this->beta / $dt) * $resLon;

            // Update state
            $state['lat'] = $newLat;
            $state['lon'] = $newLon;
            $state['v_lat'] = $newVLat;
            $state['v_lon'] = $newVLon;
            $prevTimestamp = $currentTs;

            // Persist smoothed coordinate back onto the point
            $point->coordinate = [$newLat, $newLon];

            yield $point;
        }
    }
}


