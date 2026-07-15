# GPS Ingest Go Service — Production Deployment Guide

This document is the canonical runbook for deploying the Go GPS ingest service on the production server. Follow the steps in order. Do not skip verification gates between phases.

## Architecture

```
GPS devices
    → nginx (public)
    → Go service :8081 (internal)
        → mysql_gps (batch INSERT IGNORE)
        → main MySQL (tractor status UPDATE)
        → Redis (gps:device:{imei} cache, gps_side_effects_inbox)
        → Reverb/Pusher (report-received, tractor.status.changed)
    → Laravel gps:consume-side-effects (task zone logic via ReportReceivedListener)
```

**Production routing:** nginx sends `POST /api/gps/reports` directly to Go. Laravel ingest/broadcast queue workers are stopped after cutover. Laravel fallback code remains in the repo.

## Assumptions

| Item | Default value |
|------|---------------|
| App root | `/home/api/public_html` |
| Linux user | `api` |
| Go listen address | `127.0.0.1:8081` (not public) |
| Env file | `/home/api/public_html/.env` |
| systemd unit | `gps-ingest` |
| Supervisor | `/etc/supervisor/conf.d/` |

If paths differ on the target server, update them in `services/gps-ingest/deploy/gps-ingest.service` and supervisor configs before installing.

## Related docs

| Document | When to use |
|----------|-------------|
| [Shadow validation checklist](gps-ingest-shadow-validation.md) | Phase 5 — daily checks for 7 days |
| [Monitoring and alerting](gps-ingest-monitoring.md) | After cutover — ongoing ops |
| [Rollback runbook](gps-ingest-rollback-runbook.md) | Emergency revert to Laravel |

## Prerequisites

Before starting, confirm:

- [ ] Go **1.22+** installed on the build host
- [ ] MySQL reachable (main DB + `mysql_gps` / `DB_GPS_*`)
- [ ] Redis reachable (same instance as Laravel)
- [ ] Laravel Reverb running and reachable from the Go process
- [ ] nginx installed; for shadow mode, `ngx_http_mirror_module` enabled:

```bash
nginx -V 2>&1 | grep mirror
```

- [ ] Port `8081` is **not** exposed publicly (only nginx proxies to it)

---

## Phase 0 — Deploy application code

```bash
cd /home/api/public_html
git pull origin <branch>
```

**Gate:** `ls services/gps-ingest/cmd/server/main.go` exists.

---

## Phase 1 — Configure environment

Edit `/home/api/public_html/.env`. Required variables:

```env
# Device IP allowlist (comma-separated)
GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS=94.101.187.206

# GPS database (falls back to DB_* if unset)
DB_GPS_HOST=127.0.0.1
DB_GPS_PORT=3306
DB_GPS_DATABASE=pistatapp
DB_GPS_USERNAME=...
DB_GPS_PASSWORD=...
DB_GPS_POOL_MAX=64

# Main database (tractor status updates)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pistatapp
DB_USERNAME=...
DB_PASSWORD=...
DB_MAIN_POOL_MAX=16

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# Reverb (Go broadcasts via Pusher-compatible API)
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

BROADCAST_DRIVER=reverb
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

# Laravel delegation fallback (not used when nginx routes to Go)
GPS_INGEST_DRIVER=laravel
GPS_INGEST_GO_URL=http://127.0.0.1:8081

# Go service (optional tuning — defaults are production-ready)
GPS_INGEST_HTTP_ADDR=:8081
INGEST_WORKERS=64
INGEST_CHANNEL_SIZE=100000
BATCH_FLUSH_INTERVAL_MS=50
BATCH_FLUSH_SIZE=1000
BROADCAST_WORKERS=32
SIDE_EFFECT_WORKERS=16
```

**Gate:** `grep -E '^(DB_GPS_|REVERB_|REDIS_|GPS_REPORTS_)' /home/api/public_html/.env` shows all required keys.

---

## Phase 2 — Build the Go binary

```bash
cd /home/api/public_html/services/gps-ingest
make tidy
make test
make build
```

Binary output: `/home/api/public_html/services/gps-ingest/bin/gps-ingest`

Smoke test (foreground, then stop):

```bash
cd /home/api/public_html/services/gps-ingest
./bin/gps-ingest &
sleep 2
curl -sf http://127.0.0.1:8081/healthz    # expect: ok
curl -sf http://127.0.0.1:8081/metrics    # expect: prometheus-style output
kill %1
```

**Gate:** `make test` exits 0 and `curl healthz` returns success.

---

## Phase 3 — Warm IMEI device cache

Run after every deploy and whenever GPS devices are added or reassigned:

```bash
cd /home/api/public_html
php artisan gps:warm-device-cache
```

This writes `gps:device:{imei}` JSON keys to Redis (snake_case: `tractor_id`, `device_id`) that the Go resolver reads.

**Gate:** `redis-cli KEYS 'gps:device:*' | head` returns keys.

---

## Phase 4 — Install and start systemd service

```bash
sudo cp /home/api/public_html/services/gps-ingest/deploy/gps-ingest.service \
        /etc/systemd/system/gps-ingest.service

# Verify WorkingDirectory, User, EnvironmentFile, ExecStart paths match the server
sudo systemctl daemon-reload
sudo systemctl enable gps-ingest
sudo systemctl start gps-ingest
sudo systemctl status gps-ingest
```

Verify:

```bash
curl -sf http://127.0.0.1:8081/healthz
journalctl -u gps-ingest -n 50 --no-pager
```

**Gate:** `systemctl is-active gps-ingest` is `active` and `/healthz` returns ok.

---

## Phase 5 — Start Laravel side-effect consumer

Go pushes task-zone payloads to Redis list `gps_side_effects_inbox`. Laravel drains them:

```bash
sudo cp /home/api/public_html/deploy/supervisor/laravel-gps-side-effects-consumer.conf \
        /etc/supervisor/conf.d/laravel-gps-side-effects-consumer.conf

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start gps-side-effects-consumer:*
sudo supervisorctl status gps-side-effects-consumer:*
```

**Gate:** Two `gps-side-effects-consumer` processes show `RUNNING`.

---

## Phase 6 — Shadow mode (recommended, 7 days)

Go serves the client response. nginx mirrors the same request to Laravel so both paths process data in parallel.

### 6a. Configure nginx shadow block

Add the contents of `services/gps-ingest/deploy/nginx-gps-shadow.conf` inside the site `server` block, **before** the general `location /`:

```nginx
location = /api/gps/reports {
    mirror /mirror-laravel-gps;
    proxy_pass         http://127.0.0.1:8081;
    proxy_set_header   X-Real-IP $remote_addr;
    proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 5s;
}

location /mirror-laravel-gps {
    internal;
    proxy_pass http://127.0.0.1:80/api/gps/reports;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 6b. Keep Laravel GPS workers running

During shadow mode, **do not** disable these supervisor programs:

- `gps-processing` (32 workers)
- `gps-broadcast` (16 workers)

### 6c. Daily validation (7 consecutive days)

Follow [gps-ingest-shadow-validation.md](gps-ingest-shadow-validation.md). Minimum daily commands:

```bash
curl -sf http://127.0.0.1:8081/healthz
curl -sf http://127.0.0.1:8081/metrics | grep -E 'ingest_|broadcast_|dropped_'
redis-cli LLEN gps_side_effects_inbox
journalctl -u gps-ingest --since "24 hours ago" | grep -i error
```

**Gate (proceed to Phase 7 only when all are true):**

- [ ] 7 consecutive days with no unexplained `gps_data` row count divergence
- [ ] No sustained growth in `ingest_backpressure_total` or `dropped_broadcast_total`
- [ ] WebSocket events verified in staging/production UI
- [ ] `gps_side_effects_inbox` stays near zero

---

## Phase 7 — Production cutover

### 7a. Switch nginx to production config

Replace the shadow block with `services/gps-ingest/deploy/nginx-gps-production.conf`:

```nginx
location = /api/gps/reports {
    proxy_pass         http://127.0.0.1:8081;
    proxy_set_header   X-Real-IP $remote_addr;
    proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 5s;
}
```

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 7b. Disable Laravel GPS ingest and broadcast workers

```bash
sudo cp /home/api/public_html/deploy/supervisor/gps-go-cutover.conf \
        /etc/supervisor/conf.d/gps-go-cutover.conf

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl stop gps-processing:* gps-broadcast:*
sudo supervisorctl status
```

This sets `autostart=false` on `gps-processing` and `gps-broadcast`. Configs remain for rollback.

### 7c. Post-cutover smoke test

```bash
# Health
curl -sf http://127.0.0.1:8081/healthz

# Ingest (must originate from an allowlisted IP)
curl -X POST https://<your-domain>/api/gps/reports \
  -H "Content-Type: application/json" \
  -d '{
    "data": [{
      "coordinate": [35.937893, 50.065403],
      "speed": 0,
      "status": 0,
      "directions": {"ew": 3, "ns": 1},
      "date_time": "2026-02-25 18:49:45",
      "imei": "<known-test-imei>"
    }]
  }'

# Row landed in gps_data
mysql -e "SELECT id FROM gps_data WHERE imei='<known-test-imei>' ORDER BY id DESC LIMIT 1;" <DB_GPS_DATABASE>

# Side-effect queue draining
redis-cli LLEN gps_side_effects_inbox

# Workers
sudo supervisorctl status gps-side-effects-consumer:*
sudo systemctl status gps-ingest
```

Also verify live map updates in the browser (Reverb `report-received` / `tractor.status.changed`).

**Gate:** Ingest returns 200, row appears in DB, inbox length stable, WebSocket events arrive.

---

## Phase 8 — Ongoing operations

### Service updates

```bash
cd /home/api/public_html
git pull
cd services/gps-ingest
make build
sudo systemctl restart gps-ingest
php artisan gps:warm-device-cache   # if devices changed
```

### Monitoring

Follow [gps-ingest-monitoring.md](gps-ingest-monitoring.md). Key checks:

```bash
curl -s http://127.0.0.1:8081/metrics
redis-cli LLEN gps_side_effects_inbox
journalctl -u gps-ingest -f
sudo supervisorctl status gps-side-effects-consumer:*
```

Alert when:

- `ingest_channel_depth` > 80% of `INGEST_CHANNEL_SIZE`
- Sustained `broadcast_errors_total` or `dropped_broadcast_total` growth
- `gps_side_effects_inbox` LLEN > 100
- `/healthz` returns non-200

---

## Rollback

Instant revert to Laravel without a code deploy. Full procedure: [gps-ingest-rollback-runbook.md](gps-ingest-rollback-runbook.md).

Summary:

1. Comment out the nginx `location = /api/gps/reports` Go block → `sudo nginx -t && sudo systemctl reload nginx`
2. Set `autostart=true` on `gps-processing` and `gps-broadcast` → `supervisorctl update && supervisorctl start gps-processing:* gps-broadcast:*`
3. Optionally: `sudo systemctl stop gps-ingest`

---

## Agent deployment checklist

Use this checklist when an agent deploys end-to-end:

| Step | Command / action | Expected result |
|------|------------------|-----------------|
| 1 | `git pull` in app root | Latest code present |
| 2 | Verify `.env` keys | DB, Redis, Reverb, allowlist set |
| 3 | `make tidy && make test && make build` | Exit 0; binary at `bin/gps-ingest` |
| 4 | `php artisan gps:warm-device-cache` | Redis keys `gps:device:*` exist |
| 5 | Install + start `gps-ingest.service` | `active`; `/healthz` ok |
| 6 | Install + start `gps-side-effects-consumer` | 2 RUNNING processes |
| 7 | nginx shadow config + reload | `nginx -t` ok; traffic reaches Go |
| 8 | 7-day shadow validation | All exit criteria met |
| 9 | nginx production config + reload | Mirror block removed |
| 10 | Apply `gps-go-cutover.conf` | PHP ingest/broadcast workers stopped |
| 11 | Smoke test ingest + WebSocket | 200, DB row, live map update |

## Deploy artifact reference

| File | Purpose |
|------|---------|
| `services/gps-ingest/bin/gps-ingest` | Compiled binary |
| `services/gps-ingest/deploy/gps-ingest.service` | systemd unit |
| `services/gps-ingest/deploy/nginx-gps-shadow.conf` | Phase 6 shadow nginx |
| `services/gps-ingest/deploy/nginx-gps-production.conf` | Phase 7 production nginx |
| `deploy/supervisor/laravel-gps-side-effects-consumer.conf` | Side-effect consumer |
| `deploy/supervisor/gps-go-cutover.conf` | Disable PHP GPS workers after cutover |
