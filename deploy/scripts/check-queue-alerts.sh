#!/usr/bin/env bash
#
# Phase 4 monitoring thresholds — run from cron every 5 minutes or use with alerting.
# Exits non-zero when any threshold is breached.
#
set -euo pipefail

APP_ROOT="${1:-/home/api/public_html}"
SCHEDULER_MAX="${SCHEDULER_MAX:-1}"
WORKER_MAX="${WORKER_MAX:-15}"
GPS_QUEUE_MAX="${GPS_QUEUE_MAX:-100}"
DEFAULT_QUEUE_MAX="${DEFAULT_QUEUE_MAX:-500}"

cd "$APP_ROOT"
ALERTS=0

schedule_count=$(ps aux | grep -E '[a]rtisan schedule:run' | wc -l | tr -d ' ')
worker_count=$(ps aux | grep -E '[a]rtisan queue:work' | wc -l | tr -d ' ')

if [[ "$schedule_count" -gt "$SCHEDULER_MAX" ]]; then
    echo "ALERT: schedule:run processes=$schedule_count (max $SCHEDULER_MAX)"
    ALERTS=$((ALERTS + 1))
fi

if [[ "$worker_count" -gt "$WORKER_MAX" ]]; then
    echo "ALERT: queue:work processes=$worker_count (max $WORKER_MAX)"
    ALERTS=$((ALERTS + 1))
fi

if command -v redis-cli >/dev/null 2>&1; then
    gps_depth=$(redis-cli LLEN queues:gps-processing 2>/dev/null || echo 0)
    default_depth=$(redis-cli LLEN queues:default 2>/dev/null || echo 0)

    if [[ "$gps_depth" -gt "$GPS_QUEUE_MAX" ]]; then
        echo "ALERT: queues:gps-processing depth=$gps_depth (max $GPS_QUEUE_MAX)"
        ALERTS=$((ALERTS + 1))
    fi

    if [[ "$default_depth" -gt "$DEFAULT_QUEUE_MAX" ]]; then
        echo "ALERT: queues:default depth=$default_depth (max $DEFAULT_QUEUE_MAX)"
        ALERTS=$((ALERTS + 1))
    fi
fi

if [[ "$ALERTS" -gt 0 ]]; then
    exit 1
fi

echo "OK: schedule=$schedule_count workers=$worker_count"
exit 0
