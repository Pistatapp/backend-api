#!/bin/bash
#
# PiStat API production deploy + GPS ingest health gate.
# Run on the API host after pushing to origin/main.
#
# Exit codes:
#   0 — deploy OK and no CRITICAL GPS issues
#   1 — deploy failed
#   2 — deploy OK but CRITICAL GPS health issues (do not replay gateway until fixed)
#

set -euo pipefail

PROJECT_DIR="/home/api/domains/api.pistatapp.ir/public_html"
BRANCH="${DEPLOY_BRANCH:-main}"
REDIS_CLI="${REDIS_CLI:-redis-cli}"
# After queue:restart, supervisors cycle STARTING→RUNNING. Give them time.
WORKER_SETTLE_SECONDS="${WORKER_SETTLE_SECONDS:-12}"
WORKER_RETRY_ATTEMPTS="${WORKER_RETRY_ATTEMPTS:-5}"
WORKER_RETRY_SLEEP="${WORKER_RETRY_SLEEP:-3}"
# Minimum RUNNING workers required (eco1-small should use modest numprocs).
MIN_GPS_PROCESSING="${MIN_GPS_PROCESSING:-1}"
MIN_GPS_BROADCAST="${MIN_GPS_BROADCAST:-1}"

CRITICAL=0
WARNINGS=0

red()    { printf '\033[0;31m%s\033[0m\n' "$*"; }
yellow() { printf '\033[0;33m%s\033[0m\n' "$*"; }
green()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
blue()   { printf '\033[0;34m%s\033[0m\n' "$*"; }

crit() { CRITICAL=$((CRITICAL + 1)); red "  ✖ CRITICAL: $*"; }
warn() { WARNINGS=$((WARNINGS + 1)); yellow "  ⚠ WARNING: $*"; }
ok()   { green "  ✔ $*"; }
info() { echo "  • $*"; }

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        crit "command not found: $1"
        return 1
    fi
    return 0
}

supervisor_gps_status() {
    sudo supervisorctl status 2>/dev/null | grep -E 'gps-processing|gps-broadcast|gps-side-effects' || true
}

# True if this supervisor process is a gps-processing worker (flat or group-prefixed).
# Examples: gps-processing:gps-processing_00  OR  gps-ingest:gps-processing_00
is_gps_processing() {
    [[ "$1" == *gps-processing_* ]] || [[ "$1" == gps-processing:* ]]
}

is_gps_broadcast() {
    [[ "$1" == *gps-broadcast_* ]] || [[ "$1" == gps-broadcast:* ]]
}

start_gps_workers_best_effort() {
    # Flat program names (preferred confs without [group:])
    sudo supervisorctl start \
        gps-processing:* \
        gps-broadcast:* \
        gps-side-effects:* \
        gps-side-effects-consumer:* >/dev/null 2>&1
    # Legacy group-prefixed names still on some hosts
    sudo supervisorctl start \
        gps-ingest:* \
        gps-broadcast-group:* \
        gps-side-effects-group:* \
        gps-side-effects-consumer-group:* >/dev/null 2>&1
}

# Evaluate supervisor lines in the CURRENT shell (no pipe subshell — CRITICAL must stick).
evaluate_supervisor_gps() {
    local sup_out="$1"
    local quiet="${2:-0}"
    local proc_n=0 bcast_n=0 proc_run=0 bcast_run=0
    local bad_hard=0 starting=0
    local line name state

    if [[ -z "$sup_out" ]]; then
        crit "no gps-* programs in supervisor (gps-processing / gps-broadcast missing)"
        info "install: sudo cp deploy/supervisor/laravel-gps-workers.conf /etc/supervisor/conf.d/"
        info "         sudo cp deploy/supervisor/laravel-gps-broadcast.conf /etc/supervisor/conf.d/"
        info "         sudo supervisorctl reread && sudo supervisorctl update"
        info "         then: sudo supervisorctl start gps-processing:* gps-broadcast:*"
        info "         or legacy groups: sudo supervisorctl start gps-ingest:* gps-broadcast-group:*"
        return 1
    fi

    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        name="$(echo "$line" | awk '{print $1}')"
        state="$(echo "$line" | awk '{print $2}')"

        if is_gps_processing "$name"; then
            proc_n=$((proc_n + 1))
        elif is_gps_broadcast "$name"; then
            bcast_n=$((bcast_n + 1))
        fi

        case "$state" in
            RUNNING)
                if is_gps_processing "$name"; then
                    proc_run=$((proc_run + 1))
                elif is_gps_broadcast "$name"; then
                    bcast_run=$((bcast_run + 1))
                fi
                [[ "$quiet" == "0" ]] && ok "supervisor $name RUNNING"
                ;;
            STARTING)
                starting=$((starting + 1))
                [[ "$quiet" == "0" ]] && warn "supervisor $name still STARTING (settling after restart)"
                ;;
            FATAL|BACKOFF)
                bad_hard=$((bad_hard + 1))
                crit "supervisor $name is $state — restart or scale down workers"
                ;;
            EXITED|STOPPED)
                # Often transient after queue:restart or OOM under too many numprocs.
                # Capacity gate below decides CRITICAL vs WARNING.
                bad_hard=$((bad_hard + 1))
                [[ "$quiet" == "0" ]] && warn "supervisor $name is $state"
                ;;
            *)
                warn "supervisor $name unexpected state: $state"
                ;;
        esac
    done <<< "$sup_out"

    if (( proc_n > 16 )); then
        warn "gps-processing has ${proc_n} processes — too many for a small VPS; scale down to numprocs=4 (see deploy/supervisor/GPS-WORKERS.md)"
    fi
    if (( bcast_n > 8 )); then
        warn "gps-broadcast has ${bcast_n} processes — scale down to numprocs=2"
    fi

    if (( proc_run < MIN_GPS_PROCESSING )); then
        crit "gps-processing RUNNING=${proc_run}/${proc_n} (need >= ${MIN_GPS_PROCESSING}) — GPS will NOT persist to DB"
    else
        ok "gps-processing RUNNING count = ${proc_run}/${proc_n}"
        if (( bad_hard > 0 )); then
            warn "${bad_hard} gps worker(s) not RUNNING — capacity OK for now; scale down / start them to stop flapping"
        fi
    fi
    if (( bcast_run < MIN_GPS_BROADCAST )); then
        crit "gps-broadcast RUNNING=${bcast_run}/${bcast_n} (need >= ${MIN_GPS_BROADCAST}) — map WS broadcast queue will stall"
    else
        ok "gps-broadcast RUNNING count = ${bcast_run}/${bcast_n}"
    fi

    # STARTING alone is not CRITICAL if we already have the minimum RUNNING.
    if (( starting > 0 )) && (( proc_run >= MIN_GPS_PROCESSING )) && (( bcast_run >= MIN_GPS_BROADCAST )); then
        warn "${starting} gps worker(s) still STARTING — OK if min capacity is RUNNING"
    fi

    return 0
}

echo ""
blue "════════════════════════════════════════════════════"
blue "  Pistat API deploy + GPS ingest health"
blue "════════════════════════════════════════════════════"
echo ""

cd "$PROJECT_DIR"

# ─── Deploy ───────────────────────────────────────────────────────────
blue "[1/7] Pulling origin/${BRANCH}..."
git fetch origin "$BRANCH"
git reset --hard "origin/${BRANCH}"
ok "code at $(git rev-parse --short HEAD) ($(git log -1 --pretty=format:'%s'))"

blue "[2/7] Composer install..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
ok "composer dependencies ready"

blue "[3/7] Migrations..."
php artisan migrate --force
ok "migrations applied"

blue "[4/7] Clear + rebuild caches..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
ok "caches rebuilt"

blue "[5/7] Signal queue workers to restart..."
php artisan queue:restart || warn "queue:restart failed (workers may be down)"
ok "queue restart signal sent"

# ─── Health checks (do not abort deploy mid-flight; report at end) ─────
blue "[6/7] GPS / Redis / Supervisor health checks..."
echo ""

set +e

# Redis
if require_cmd "$REDIS_CLI"; then
    PING="$($REDIS_CLI ping 2>&1)"
    if [[ "$PING" == "PONG" ]]; then
        ok "Redis ping = PONG"
    elif echo "$PING" | grep -qi loading; then
        crit "Redis is LOADING dataset — queue ingest will fail until load finishes"
        info "wait and re-run: $REDIS_CLI ping"
    else
        crit "Redis ping failed: $PING"
    fi

    for q in gps-processing gps-broadcast gps-side-effects default; do
        LEN="$($REDIS_CLI LLEN "queues:${q}" 2>/dev/null || echo err)"
        if [[ "$LEN" == "err" ]]; then
            warn "cannot read queue length for queues:${q}"
        else
            info "queue queues:${q} length = ${LEN}"
            if [[ "$q" == "gps-processing" ]] && [[ "$LEN" =~ ^[0-9]+$ ]] && (( LEN > 5000 )); then
                warn "gps-processing backlog is high (${LEN}) — check workers / Redis"
            fi
        fi
    done
else
    crit "redis-cli missing — cannot verify Redis"
fi

echo ""

# Supervisor GPS programs — wait for settle after queue:restart, then retry
if command -v supervisorctl >/dev/null 2>&1; then
    info "waiting ${WORKER_SETTLE_SECONDS}s for workers to settle after queue:restart..."
    sleep "$WORKER_SETTLE_SECONDS"

    # Best-effort: revive EXITED/FATAL (flat + legacy group names)
    start_gps_workers_best_effort

    attempt=1
    while (( attempt <= WORKER_RETRY_ATTEMPTS )); do
        SUP_OUT="$(supervisor_gps_status)"
        STARTING_N="$(echo "$SUP_OUT" | grep -c 'STARTING' || true)"
        EXITED_N="$(echo "$SUP_OUT" | grep -cE 'EXITED|FATAL|STOPPED|BACKOFF' || true)"
        PROC_RUN="$(echo "$SUP_OUT" | grep 'gps-processing' | grep -c RUNNING || true)"
        BCAST_RUN="$(echo "$SUP_OUT" | grep 'gps-broadcast' | grep -c RUNNING || true)"

        if (( STARTING_N == 0 )) && (( EXITED_N == 0 )) && (( PROC_RUN >= MIN_GPS_PROCESSING )) && (( BCAST_RUN >= MIN_GPS_BROADCAST )); then
            break
        fi
        if (( attempt < WORKER_RETRY_ATTEMPTS )); then
            info "workers settling (attempt ${attempt}/${WORKER_RETRY_ATTEMPTS}): STARTING=${STARTING_N} BAD=${EXITED_N} proc_run=${PROC_RUN} bcast_run=${BCAST_RUN}"
            if (( EXITED_N > 0 )); then
                start_gps_workers_best_effort
            fi
            sleep "$WORKER_RETRY_SLEEP"
        fi
        attempt=$((attempt + 1))
    done

    SUP_OUT="$(supervisor_gps_status)"
    evaluate_supervisor_gps "$SUP_OUT" 0
else
    crit "supervisorctl not found — cannot verify GPS workers"
fi

echo ""

# Artisan GPS health (code + DB + Redis via Laravel)
if php artisan list --raw 2>/dev/null | grep -q '^gps:ingest-health'; then
    php artisan gps:ingest-health
    ART_RC=$?
    if (( ART_RC != 0 )); then
        crit "php artisan gps:ingest-health reported failures (exit ${ART_RC})"
    else
        ok "php artisan gps:ingest-health passed"
    fi
else
    warn "gps:ingest-health command missing — deploy includes app/Console/Commands/GpsIngestHealthCommand.php?"
    if grep -q 'normalizeDeviceDateTime' app/Jobs/IngestGpsData.php 2>/dev/null; then
        ok "IngestGpsData clock-resync code present"
    else
        crit "IngestGpsData missing normalizeDeviceDateTime — stuck device clocks will break today path"
    fi
fi

echo ""

# Failed jobs table (best-effort)
FAILED_N="$(php artisan queue:failed 2>/dev/null | grep -cE '^[0-9]+' || true)"
if [[ -n "$FAILED_N" ]] && (( FAILED_N > 0 )); then
    warn "failed jobs listed: ${FAILED_N} (run: php artisan queue:failed)"
else
    ok "no failed jobs reported (or table empty)"
fi

# Env sanity
if [[ -f .env ]]; then
    DRIVER="$(grep -E '^GPS_INGEST_DRIVER=' .env | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
    DRIVER="${DRIVER:-laravel}"
    info "GPS_INGEST_DRIVER=${DRIVER}"
    if [[ "$DRIVER" == "go" ]]; then
        warn "GPS_INGEST_DRIVER=go — Laravel IngestGpsData is bypassed; ensure Go gps-ingest service is healthy"
    fi

    EXEMPT="$(grep -E '^GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS=' .env | cut -d= -f2- || true)"
    if [[ -z "$EXEMPT" ]]; then
        warn "GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS empty — gateway IPs may get 403"
    else
        ok "GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS is set"
    fi
else
    crit ".env missing in ${PROJECT_DIR}"
fi

set -e

# ─── Summary ──────────────────────────────────────────────────────────
echo ""
blue "[7/7] Summary"
echo ""
if (( CRITICAL > 0 )); then
    red "Deploy finished, but ${CRITICAL} CRITICAL issue(s), ${WARNINGS} warning(s)."
    red "Do NOT replay IoT Gateway traffic until CRITICAL items are fixed."
    echo ""
    info "Typical fix for overloaded / EXITED workers on eco1-small:"
    info "  sudo cp -f deploy/supervisor/laravel-gps-*.conf /etc/supervisor/conf.d/  # or edit symlink target"
    info "  sudo supervisorctl reread && sudo supervisorctl update"
    info "  sudo supervisorctl start gps-processing:* gps-broadcast:* gps-side-effects:*"
    info "  # legacy groups: sudo supervisorctl start gps-ingest:* gps-broadcast-group:* gps-side-effects-group:*"
    info "Typical fix for Redis LOADING: wait until redis-cli ping => PONG"
    echo ""
    exit 2
fi

if (( WARNINGS > 0 )); then
    yellow "Deploy OK with ${WARNINGS} warning(s). Review above before heavy GPS replay."
    green "Deployment completed."
    exit 0
fi

green "Deployment completed successfully — GPS ingest checks look healthy."
exit 0
