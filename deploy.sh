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
        # Laravel Redis queue key is typically queues:{name}
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

# Supervisor GPS programs
if command -v supervisorctl >/dev/null 2>&1; then
    SUP_OUT="$(sudo supervisorctl status 2>/dev/null | grep -E 'gps-processing|gps-broadcast|gps-side-effects' || true)"
    if [[ -z "$SUP_OUT" ]]; then
        crit "no gps-* programs in supervisor (gps-processing / gps-broadcast missing)"
        info "install: sudo cp deploy/supervisor/laravel-gps-workers.conf /etc/supervisor/conf.d/"
        info "         sudo cp deploy/supervisor/laravel-gps-broadcast.conf /etc/supervisor/conf.d/"
        info "         sudo supervisorctl reread && sudo supervisorctl update"
        info "         sudo supervisorctl start gps-processing:* gps-broadcast:*"
    else
        echo "$SUP_OUT" | while read -r line; do
            name="$(echo "$line" | awk '{print $1}')"
            state="$(echo "$line" | awk '{print $2}')"
            if [[ "$state" == "RUNNING" ]]; then
                ok "supervisor $name RUNNING"
            else
                crit "supervisor $name is $state (expected RUNNING)"
            fi
        done

        PROC_N="$(echo "$SUP_OUT" | grep -c 'gps-processing' || true)"
        BCAST_N="$(echo "$SUP_OUT" | grep -c 'gps-broadcast' || true)"
        PROC_RUN="$(echo "$SUP_OUT" | grep 'gps-processing' | grep -c RUNNING || true)"
        BCAST_RUN="$(echo "$SUP_OUT" | grep 'gps-broadcast' | grep -c RUNNING || true)"

        if (( PROC_RUN < 1 )); then
            crit "zero RUNNING gps-processing workers — GPS will NOT persist to DB"
        else
            ok "gps-processing RUNNING count = ${PROC_RUN}/${PROC_N}"
        fi
        if (( BCAST_RUN < 1 )); then
            crit "zero RUNNING gps-broadcast workers — map WS broadcast queue will stall"
        else
            ok "gps-broadcast RUNNING count = ${BCAST_RUN}/${BCAST_N}"
        fi
    fi
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
    # Minimal inline fallbacks
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
    info "Typical fix for missing workers:"
    info "  sudo supervisorctl status | grep gps"
    info "  sudo supervisorctl start gps-processing:* gps-broadcast:*"
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
