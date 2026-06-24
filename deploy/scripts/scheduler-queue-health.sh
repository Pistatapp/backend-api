#!/usr/bin/env bash
#
# Phase 0–2 health snapshot for scheduler / queue incidents.
# Usage: ./deploy/scripts/scheduler-queue-health.sh [APP_ROOT]
#
set -euo pipefail

APP_ROOT="${1:-/home/api/public_html}"
OUTPUT_DIR="${TMPDIR:-/tmp}/scheduler-queue-health-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUTPUT_DIR"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

log "Writing snapshot to $OUTPUT_DIR"
cd "$APP_ROOT"

{
    echo "=== TIMESTAMP ==="
    date -Iseconds
    echo ""
    echo "=== UPTIME / LOAD ==="
    uptime
    echo ""
    echo "=== PROCESS COUNTS ==="
    echo "schedule:run: $(ps aux | grep -E '[a]rtisan schedule:run' | wc -l | tr -d ' ')"
    echo "queue:work:   $(ps aux | grep -E '[a]rtisan queue:work' | wc -l | tr -d ' ')"
    echo ""
    echo "=== schedule:run PROCESSES ==="
    ps aux | grep -E '[a]rtisan schedule:run' || true
    echo ""
    echo "=== queue:work PROCESSES ==="
    ps aux | grep -E '[a]rtisan queue:work' || true
} > "$OUTPUT_DIR/processes.txt"

php artisan app:debug-scheduler-queue-health --output="$OUTPUT_DIR/artisan-report.json" 2>&1 | tee "$OUTPUT_DIR/artisan-report.txt"

if command -v redis-cli >/dev/null 2>&1; then
    {
        echo "=== REDIS QUEUE DEPTHS ==="
        redis-cli LLEN queues:default 2>/dev/null || echo "queues:default: unavailable"
        redis-cli LLEN queues:gps-processing 2>/dev/null || echo "queues:gps-processing: unavailable"
        echo ""
        echo "=== REDIS MEMORY ==="
        redis-cli INFO memory 2>/dev/null | grep used_memory_human || true
    } > "$OUTPUT_DIR/redis.txt"
fi

if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl status 2>&1 > "$OUTPUT_DIR/supervisor.txt" || true
fi

crontab -l 2>&1 > "$OUTPUT_DIR/crontab.txt" || true

for f in storage/logs/worker.log storage/logs/gps-processing.log storage/logs/laravel.log; do
    if [[ -f "$f" ]]; then
        cp "$f" "$OUTPUT_DIR/$(basename "$f").snapshot"
        tail -500 "$f" | grep -iE 'timeout|fail|MaxAttempts|Killed|ProcessGps|CalculateGps' > "$OUTPUT_DIR/$(basename "$f").errors" 2>/dev/null || true
    fi
done

log "Snapshot complete: $OUTPUT_DIR"
ls -la "$OUTPUT_DIR"
