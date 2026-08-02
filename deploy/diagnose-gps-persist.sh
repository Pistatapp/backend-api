#!/bin/bash
#
# Diagnose why PiStat GPS logs are not persisting to mysql_gps.gps_data
# while live WebSocket markers may still move.
#
# Usage (on API host):
#   cd /home/api/domains/api.pistatapp.ir/public_html
#   chmod +x deploy/diagnose-gps-persist.sh
#   ./deploy/diagnose-gps-persist.sh
#   ./deploy/diagnose-gps-persist.sh --tractor=38
#   ./deploy/diagnose-gps-persist.sh --no-smoke
#

set -u

PROJECT_DIR="${PROJECT_DIR:-/home/api/domains/api.pistatapp.ir/public_html}"
TRACTOR="${TRACTOR:-38}"
DO_SMOKE=1
HOURS="${HOURS:-6}"
REDIS_CLI="${REDIS_CLI:-redis-cli}"

for arg in "$@"; do
    case "$arg" in
        --tractor=*) TRACTOR="${arg#*=}" ;;
        --hours=*) HOURS="${arg#*=}" ;;
        --no-smoke) DO_SMOKE=0 ;;
        --help|-h)
            echo "Usage: $0 [--tractor=38] [--hours=6] [--no-smoke]"
            exit 0
            ;;
    esac
done

red()    { printf '\033[0;31m%s\033[0m\n' "$*"; }
yellow() { printf '\033[0;33m%s\033[0m\n' "$*"; }
green()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
blue()   { printf '\033[0;34m%s\033[0m\n' "$*"; }

CRITICAL=0
WARNINGS=0
crit() { CRITICAL=$((CRITICAL + 1)); red "✖ CRITICAL: $*"; }
warn() { WARNINGS=$((WARNINGS + 1)); yellow "⚠ WARNING: $*"; }
ok()   { green "✔ $*"; }

echo ""
blue "════════════════════════════════════════════════════"
blue "  PiStat GPS persist diagnose"
blue "════════════════════════════════════════════════════"
echo "host=$(hostname)  pwd=$(pwd)  tractor=${TRACTOR}"
echo ""

if [[ ! -d "$PROJECT_DIR" ]]; then
    crit "PROJECT_DIR not found: $PROJECT_DIR"
    exit 1
fi
cd "$PROJECT_DIR" || exit 1
ok "project: $PROJECT_DIR"
echo "git: $(git rev-parse --short HEAD 2>/dev/null || echo '?') $(git log -1 --pretty=format:%s 2>/dev/null || true)"
echo ""

# ── Host: Redis ──────────────────────────────────────────────────────
blue "[A] Redis"
if command -v "$REDIS_CLI" >/dev/null 2>&1; then
    PING="$($REDIS_CLI ping 2>&1 || true)"
    if [[ "$PING" == "PONG" ]]; then
        ok "redis-cli ping = PONG"
    elif echo "$PING" | grep -qi loading; then
        crit "Redis LOADING — queue workers cannot consume IngestGpsData"
    else
        crit "redis-cli ping failed: $PING"
    fi
    for q in gps-processing gps-broadcast gps-side-effects default; do
        LEN="$($REDIS_CLI LLEN "queues:${q}" 2>/dev/null || echo err)"
        echo "  • queues:${q} = ${LEN}"
    done
else
    warn "redis-cli not found"
fi
echo ""

# ── Host: Supervisor GPS workers ─────────────────────────────────────
blue "[B] Supervisor GPS workers"
if command -v supervisorctl >/dev/null 2>&1; then
    SUP="$(sudo supervisorctl status 2>/dev/null | grep -E 'gps-processing|gps-broadcast|gps-ingest|gps-side' || true)"
    if [[ -z "$SUP" ]]; then
        crit "No gps-* programs in supervisor — IngestGpsData never runs"
        echo "  install from deploy/supervisor/*.conf then: sudo supervisorctl reread && update && start ..."
    else
        echo "$SUP"
        PROC_RUN="$(echo "$SUP" | grep -E 'gps-processing|gps-ingest:gps-processing' | grep -c RUNNING || true)"
        BCAST_RUN="$(echo "$SUP" | grep -E 'gps-broadcast' | grep -c RUNNING || true)"
        if (( PROC_RUN < 1 )); then
            crit "zero RUNNING gps-processing workers — DB writes will not happen"
        else
            ok "gps-processing RUNNING count=${PROC_RUN}"
        fi
        if (( BCAST_RUN < 1 )); then
            warn "zero RUNNING gps-broadcast — live map WS may stall (DB can still write)"
        else
            ok "gps-broadcast RUNNING count=${BCAST_RUN}"
        fi
        BAD="$(echo "$SUP" | grep -cE 'FATAL|EXITED|BACKOFF|STOPPED' || true)"
        if (( BAD > 0 )); then
            warn "${BAD} gps worker(s) not healthy (FATAL/EXITED/BACKOFF/STOPPED)"
        fi
    fi
else
    crit "supervisorctl not found"
fi
echo ""

# ── Host: PHP / artisan availability ─────────────────────────────────
blue "[C] Artisan diagnose (Laravel)"
if [[ ! -f artisan ]]; then
    crit "artisan missing in $PROJECT_DIR"
else
    if ! php artisan list --raw 2>/dev/null | grep -q '^gps:diagnose-persist'; then
        warn "gps:diagnose-persist not registered — pull latest code, then re-run"
        echo "  falling back to gps:ingest-health --fast + gps:persist-smoke"
        php artisan gps:ingest-health --fast || true
        if (( DO_SMOKE == 1 )); then
            php artisan gps:persist-smoke --tractor="$TRACTOR" || crit "gps:persist-smoke failed"
        fi
    else
        SMOKE_FLAG=""
        if (( DO_SMOKE == 1 )); then
            SMOKE_FLAG="--smoke"
        fi
        blue "  running: php artisan gps:diagnose-persist --tractor=${TRACTOR} --hours=${HOURS} ${SMOKE_FLAG}"
        yellow "  (smoke has 5s MySQL lock wait; if still stuck, Ctrl+C and check PROCESSLIST)"
        if command -v timeout >/dev/null 2>&1; then
            timeout --signal=TERM 60 php artisan gps:diagnose-persist --tractor="$TRACTOR" --hours="$HOURS" $SMOKE_FLAG
            ART_RC=$?
            if (( ART_RC == 124 )); then
                crit "diagnose timed out after 60s — almost certainly MySQL lock on gps_data (p_future REORGANIZE/ALTER)"
                echo "  Run now:"
                echo "    mysql -e \"SHOW FULL PROCESSLIST;\""
                echo "    mysql api_db -e \"SELECT * FROM information_schema.innodb_trx\\G\""
            elif (( ART_RC != 0 )); then
                crit "php artisan gps:diagnose-persist exited ${ART_RC}"
            else
                ok "php artisan gps:diagnose-persist finished"
            fi
        else
            php artisan gps:diagnose-persist --tractor="$TRACTOR" --hours="$HOURS" $SMOKE_FLAG
            ART_RC=$?
            if (( ART_RC != 0 )); then
                crit "php artisan gps:diagnose-persist exited ${ART_RC}"
            else
                ok "php artisan gps:diagnose-persist finished"
            fi
        fi
    fi
fi
echo ""

# ── Host: tail worker log errors ─────────────────────────────────────
blue "[D] Recent worker / laravel log errors"
for f in storage/logs/gps-processing.log storage/logs/laravel.log; do
    if [[ -f "$f" ]]; then
        echo "  --- last matches in $f ---"
        grep -Eia 'IngestGpsData|gone away|partition|LOADING|persisted 0|unbound IMEI|SQLSTATE' "$f" 2>/dev/null | tail -n 20 || echo "  (no matches)"
    else
        echo "  • missing $f"
    fi
done
echo ""

# ── Quick SQL hint ───────────────────────────────────────────────────
blue "[E] Manual SQL checks (copy/paste in mysql client)"
cat <<EOF
  SELECT COUNT(*) AS today_all
  FROM gps_data
  WHERE date_time >= CURDATE() AND date_time < (CURDATE() + INTERVAL 2 DAY);

  SELECT COUNT(*) AS today_t, MIN(date_time), MAX(date_time)
  FROM gps_data
  WHERE tractor_id = ${TRACTOR}
    AND date_time >= CURDATE() AND date_time < (CURDATE() + INTERVAL 2 DAY);

  SHOW INDEX FROM gps_data WHERE Key_name = 'gps_data_imei_date_time_unique';
EOF
echo ""

blue "════════════════════════════════════════════════════"
if (( CRITICAL > 0 )); then
    red "RESULT: ${CRITICAL} CRITICAL, ${WARNINGS} warning(s) — persist is blocked."
    echo "Typical fixes:"
    echo "  1) redis-cli ping  → must be PONG"
    echo "  2) sudo supervisorctl status | grep gps  → gps-processing RUNNING"
    echo "  3) php artisan gps:persist-smoke --tractor=${TRACTOR}"
    echo "  4) tail -f storage/logs/gps-processing.log"
    exit 2
fi
if (( WARNINGS > 0 )); then
    yellow "RESULT: 0 CRITICAL, ${WARNINGS} warning(s) — review above."
    exit 0
fi
green "RESULT: host checks look OK. If DB still empty, replay gateway and re-check today COUNT."
exit 0
