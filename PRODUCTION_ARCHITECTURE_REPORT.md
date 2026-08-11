# PiStat Production Architecture Report

**Host:** `ubuntu-eco1-small1-bamdad-1`  
**Primary API domain:** `api.pistatapp.ir`  
**WebSocket domain:** `ws.pistatapp.ir`  
**Public IP:** `188.121.119.99`  
**Audit date:** 2026-08-04  
**Auditor mode:** Read-only inspection — no changes applied

---

## Executive Summary

PiStat production runs on a **4-vCPU / 8 GB RAM Ubuntu 24.04 VPS** managed via **DirectAdmin**, serving a **Laravel 12.64** API with **MariaDB 10.6**, **Redis 7**, **nginx 1.29**, and **PHP 8.3 FPM**. Real-time tractor tracking uses **Laravel Reverb** on port **8088**, with GPS ingest flowing through **Redis queues** and **Supervisor-managed workers**.

The database is dominated by **`gps_data`** (~3.6 GB, ~18M rows). Disk is **89% full** (56 GB / 66 GB). Several path/configuration drifts exist between `/home/api/public_html` (legacy) and `/home/api/domains/api.pistatapp.ir/public_html` (current deploy target). **Security hardening is required** before staging parity (world-readable `.env`, MySQL on `0.0.0.0`, SSH password auth enabled).

---

## 1. Server Infrastructure

### 1.1 Hardware & OS

| Attribute | Value |
|---|---|
| OS | Ubuntu 24.04.4 LTS (Noble Numbat) |
| Kernel | 6.8.0-136-generic |
| Hypervisor | KVM (Intel Xeon Cascadelake) |
| vCPUs | 4 |
| RAM | 7.8 GiB total (~2.6 GiB used, ~5.2 GiB available) |
| Swap | **None configured** |
| Root disk | `/dev/vda3` — 66 GB total, **56 GB used (89%)**, 7 GB free |
| Uptime | ~15 days |

### 1.2 Filesystem Layout

| Mount | Size | Used | Purpose |
|---|---:|---:|---|
| `/` (`/dev/vda3`) | 66 GB | 89% | Application, DB, logs |
| `/boot/efi` | 549 MB | <1% | EFI boot |
| `tmpfs` | 796 MB | minimal | `/run`, user sessions |

**Critical:** Disk at 89% with no swap — GPS table growth and Telescope entries pose outage risk.

### 1.3 Running Services (systemd)

| Service | Role |
|---|---|
| `nginx.service` | Reverse proxy, SSL termination, static files |
| `httpd.service` | Apache HTTP Server (DirectAdmin stack) |
| `php-fpm83.service` | PHP 8.3 FastCGI (master PID active, 0 idle workers at snapshot) |
| `mysqld.service` | MariaDB 10.6.25 |
| `redis-server.service` | Redis 7.0.15 (port 6379, localhost only) |
| `redis@1002.service` | Secondary Redis instance (DirectAdmin) |
| `supervisor.service` | Queue workers, Reverb, GPS side-effects |
| `gps-ingest.service` | Go GPS ingest binary (standby/shadow path) |
| `docker.service` | Docker runtime |
| `named.service` | BIND DNS |
| `dovecot.service` / `exim.service` | Mail (IMAP/SMTP) |
| `lfd.service` | ConfigServer Firewall daemon |
| `ssh.service` | SSH on port 22 |

### 1.4 Web Stack

| Component | Version | Notes |
|---|---|---|
| nginx | 1.29.6 | Public-facing on `188.121.119.99:80/443` |
| Apache (httpd) | active | DirectAdmin integration; localhost backends |
| PHP CLI/FPM | 8.3.30 | `/usr/local/php83/` |
| MariaDB | 10.6.25-MariaDB | Listening on **`0.0.0.0:3306`** |
| Redis | 7.0.15 | `127.0.0.1:6379` only |
| Composer packages | See §2 | Laravel 12 ecosystem |

**nginx routing (inferred):**
- `api.pistatapp.ir` → Laravel `public/index.php` via PHP-FPM
- `ws.pistatapp.ir` → Reverb WebSocket proxy (port 8088)
- `POST /api/gps/reports` — production cutover config proxies to Go on `:8081` when enabled (`services/gps-ingest/deploy/nginx-gps-production.conf`); current driver is **`laravel`**

### 1.5 PHP-FPM

- Service: `php-fpm83.service` (enabled, running 3+ days)
- Config: `/usr/local/php83/etc/php-fpm.conf`
- Status at audit: 0 active/idle workers (low traffic snapshot)

### 1.6 Redis

| Metric | Value |
|---|---|
| Version | 7.0.15 |
| Mode | standalone |
| Memory used | 7.4 MB |
| Keys (db0) | 47 (27 with TTL) |
| Keys (db1) | 2 (cache) |
| Ops/sec | ~30 |
| Uptime | 3 days |
| Prefix | `{app_slug}_database_` (from `REDIS_PREFIX`) |

### 1.7 MySQL / MariaDB

| Attribute | Value |
|---|---|
| Version | MariaDB 10.6.25 |
| Database | `api_db` |
| User | `api_db` |
| Host | `127.0.0.1:3306` (app) / **`0.0.0.0:3306` (network bind)** |
| Total size | 3.77 GB |
| Tables | 74 |
| Charset | utf8mb4 / utf8mb4_unicode_ci |
| Engine | InnoDB (all application tables) |

**Connections:** `mysql` uses persistent PDO; `mysql_gps` uses non-persistent (prevents "gone away" in workers).

### 1.8 Supervisor Workers (live snapshot)

| Program | Count | Queue / Command | Status |
|---|---:|---|---|
| `gps-processing` | 4 | `queue:work redis --queue=gps-processing` | RUNNING |
| `gps-broadcast` | 2 | `queue:work redis --queue=gps-broadcast` | RUNNING |
| `gps-side-effects` | 2 | `queue:work redis --queue=gps-side-effects` | RUNNING |
| `gps-side-effects-consumer` | 1 | `gps:consume-side-effects` | RUNNING |
| `laravel-worker` | 2 | `queue:work redis --queue=default` | RUNNING |
| `laravel-reverb` | 1 | `reverb:start` | RUNNING (3+ days uptime) |

**Legacy/conf drift:** `/etc/supervisor/conf.d/` contains duplicate `[program:gps-broadcast]` blocks and symlinks to both `/home/api/public_html` and `/home/api/domains/api.pistatapp.ir/public_html`. Reverb still runs from **`/home/api/public_html`**.

**Horizon:** Installed (`laravel/horizon v5.48.1`) but **not used** for worker management — plain `queue:work` via Supervisor.

### 1.9 Cron Jobs

| Schedule | Command | User |
|---|---|---|
| `* * * * *` | `flock … php artisan schedule:run` | **⚠ Path: `/home/api/public_html`** (legacy) |
| `0 2,14 * * *` | `/opt/pistat-db-backup.sh` | root |
| `0 3 * * 0` | `/opt/pistat-weekly-full.sh` | root |
| `* * * * *` | `/usr/local/bin/gps_lag_check.sh` | root |

Laravel scheduler tasks (via `app/Console/Kernel.php` + `routes/console.php`):
- Farm plan / irrigation status — every minute
- Tractor stoppage/activity/task updates — every 1–5 minutes
- Frost/oil-spray/pest/crop degree-day jobs — daily
- Attendance summary — daily 00:00
- Close attendance sessions — hourly
- GPS metrics (`CalculateGpsMetricsJob`) — daily 23:00
- Telescope prune — daily (24h retention)
- **`gps:ensure-partitions` — explicitly NOT scheduled** (production incident)

### 1.10 Firewall & SSL

| Control | Status |
|---|---|
| UFW | **inactive** |
| CSF/LFD | **active** (`lfd.service`) |
| SSL | HTTPS working on `api.pistatapp.ir` (HTTP/2, nginx) |
| WS SSL | `ws.pistatapp.ir` configured via Reverb (`REVERB_SCHEME=https`, port 443 client-side) |

**Exposed ports (public):**

| Port | Service | Risk |
|---:|---|---|
| 22 | SSH | Password auth enabled |
| 25, 465, 587 | SMTP | Mail server |
| 80, 443 | HTTP/S | Expected |
| 110, 143, 993, 995, 4190 | Mail | Expected for mail host |
| 3306 | MySQL | **High — bound to 0.0.0.0** |
| 8088 | Reverb | **Medium — bound to 0.0.0.0** |
| 8081 | Go/Apache? | Bound to all interfaces |
| 27159 | Unknown | Investigate |

---

## 2. Application Stack

### 2.1 Versions

| Component | Version |
|---|---|
| Laravel Framework | **12.64.0** |
| PHP | **8.3.30** |
| Laravel Reverb | v1.11.0 |
| Laravel Horizon | v5.48.1 (installed, unused) |
| Laravel Sanctum | v4.3.3 |
| Laravel Telescope | v5.21.0 |
| Predis | ^2.2 |

### 2.2 Production Environment (non-secret)

| Variable | Production Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | **`true`** ⚠ |
| `BROADCAST_DRIVER` | `reverb` |
| `QUEUE_CONNECTION` | `redis` |
| `CACHE_DRIVER` | `redis` |
| `GPS_INGEST_DRIVER` | **`laravel`** |
| `TELESCOPE_ENABLED` | **`true`** ⚠ |
| `DB_DATABASE` | `api_db` |
| `REVERB_HOST` | `ws.pistatapp.ir` |
| `REVERB_SERVER_PORT` | `8088` |

### 2.3 Key Composer Packages

| Package | Purpose |
|---|---|
| `dedoc/scramble` | OpenAPI docs |
| `google/apiclient` | Google services |
| `kavenegar/laravel` | SMS (OTP) |
| `spatie/laravel-permission` | RBAC |
| `spatie/laravel-medialibrary` | Media attachments |
| `pion/laravel-chunk-upload` | Large file uploads |
| `pishran/zarinpal` | Payment gateway |
| `morilog/jalali` | Jalali calendar |
| `phpoffice/phpspreadsheet` | Excel export |

### 2.4 Application Paths

| Path | Role |
|---|---|
| `/home/api/domains/api.pistatapp.ir/public_html` | **Current deploy target** (`deploy.sh`) |
| `/home/api/public_html` | **Legacy** — Reverb, Go gps-ingest, scheduler cron, systemd gps-ingest unit |
| Owner drift | `public_html` owned by `ubuntu:ubuntu`; app runs as `api` user |

### 2.5 Custom Artisan Commands (GPS-related)

| Command | Purpose |
|---|---|
| `gps:ingest-health` | Redis, queues, workers, partition, ingest counts |
| `gps:diagnose-persist` | Debug GPS persist failures |
| `gps:ensure-partitions` | DBA-only partition REORGANIZE (**never schedule**) |
| `gps:consume-side-effects` | Consume Go `gps_side_effects_inbox` Redis list |
| `gps:path-debug` | Raw vs streamed path comparison |
| `gps:purge-future-junk` | Remove far-future junk rows |
| `gps:warm-device-cache` | Preload IMEI→tractor cache |

### 2.6 Queue Architecture

| Queue | Workers | Job Classes | Retry |
|---|---:|---|---|
| `gps-processing` | 4 | `IngestGpsData` | 3 tries, backoff [2,5,10]s, timeout 90s |
| `gps-broadcast` | 2 | `BroadcastGpsEvents` | 3 tries, timeout 60s |
| `gps-side-effects` | 2 | `UpdateTractorStatusJob`, `ReportReceivedListener` | 3 tries |
| `default` | 2 | General jobs (metrics, frost, attendance, etc.) | 3 tries |
| Failed jobs | — | `failed_jobs` table | 21 failed at audit |

**Redis queue keys:** `queues:{queue_name}` (Laravel standard)  
**Retry after:** 120s (redis connection config)

### 2.7 Reverb Configuration

| Setting | Value |
|---|---|
| Server bind | `0.0.0.0:8088` |
| Public host | `ws.pistatapp.ir:443` (HTTPS/WSS) |
| Driver | `reverb` |
| Scaling | Disabled |
| Allowed origins | `*` |
| Process | Supervisor `laravel-reverb` (from legacy path) |

**Broadcast channels:** `gps_devices.{id}`, `tractor.{id}`, `tractor.tasks.{id}`, `irrigations.{id}`, `users.{id}`

### 2.8 Storage Structure

```
storage/
├── app/           307 MB  (uploads, APK releases, chunks)
│   ├── public/
│   ├── json/
│   └── chunks/
├── logs/           13 MB  (worker, GPS, reverb logs)
└── framework/     1.5 MB  (cache, sessions, views)
```

- `FILESYSTEM_DISK=local`
- Media library via Spatie (`media` table)
- App releases stored under `storage/app/public/application-zip/`

---

## 3. GPS Architecture Summary

See **`GPS_PIPELINE_REFERENCE.md`** for full data-flow documentation.

**Current production driver:** `GPS_INGEST_DRIVER=laravel`

```
IoT Gateway → POST /api/gps/reports → IP allowlist → GpsReportController
  → IngestGpsData (gps-processing queue) → mysql_gps.gps_data INSERT
  → BroadcastGpsEvents (gps-broadcast) → Reverb WS → Mobile app
  → ReportReceivedListener (gps-side-effects) → Tractor task zone logic
```

Go ingest service runs as standby (`gps-ingest.service` from legacy path, port 8081).

---

## 4. Database Summary

See **`DATABASE_SCHEMA_REFERENCE.md`** for complete `SHOW CREATE TABLE` exports.

| Metric | Value |
|---|---|
| Total size | 3.77 GB |
| Largest table | `gps_data` — 3.61 GB (96%) |
| Row count (gps_data) | ~18.1M (includes junk future dates) |
| Partition strategy | RANGE on `date_time`; most data in `p_future` |
| Read replica | Configured (`mysql_gps_read`) — same host currently |

---

## 5. GIS Readiness Summary

See **`MIGRATION_RISKS.md`** § GIS section.

- **No PostGIS / MySQL spatial types** in use
- Coordinates stored as JSON strings (`gps_data.coordinate`) or `longtext` polygons (`farms.coordinates`, `fields.coordinates`, `plots.coordinates`)
- Point-in-polygon done in PHP application code
- No spatial indexes

---

## 6. Security Findings

| Finding | Severity | Detail |
|---|---|---|
| `.env` permissions `755`, owner `ubuntu` | **Critical** | World-readable; contains DB, Reverb, API keys |
| `APP_DEBUG=true` in production | **High** | Stack traces may leak |
| `TELESCOPE_ENABLED=true` | **High** | Debug tool in production |
| MySQL on `0.0.0.0:3306` | **Critical** | Network-exposed database |
| Reverb on `0.0.0.0:8088` | **Medium** | WebSocket without origin restriction |
| SSH `PasswordAuthentication yes` | **High** | No pubkey auth |
| SSH `PermitRootLogin yes` | **High** | Root login permitted |
| `storage/` and `bootstrap/cache/` mode `777` | **Medium** | World-writable |
| Reverb `allowed_origins: *` | **Medium** | Any origin can connect |
| GPS IP allowlist only | **Info** | Gateway protected; not auth for mobile |

**Secrets inventory (keys present in `.env`, values redacted):**
`APP_KEY`, `DB_PASSWORD`, `REVERB_APP_*`, `KAVENEGAR_API_KEY`, `FCM_SERVER_KEY`, `WEATHER_API_KEY`, `ZARINPAL_MERCHANT_ID`, `NOC_MONITOR_TOKEN`, `AWS_*`

---

## 7. Performance Snapshot

| Metric | Value |
|---|---|
| Load average | 0.15 / 0.11 / 0.04 |
| CPU idle | ~97% |
| Memory available | ~5.2 GiB |
| Disk used | 89% |
| Redis memory | 7.4 MB |
| Queue backlog | **0** on all GPS queues |
| Failed jobs | 21 |
| GPS workers | All RUNNING (11 total GPS-related) |

**Slow query risks:**
- Full `COUNT(*)` on `gps_data` took **54s** (18M rows)
- Queries scanning `p_future` partition
- `telescope_entries` at 129K rows / 88 MB
- No swap — large queries can OOM the host

---

## 8. Deployment Process

**Script:** `/home/api/domains/api.pistatapp.ir/public_html/deploy.sh`

```bash
cd /home/api/domains/api.pistatapp.ir/public_html
./deploy.sh   # or: DEPLOY_BRANCH=main ./deploy.sh
```

**Steps:**
1. `git fetch && git reset --hard origin/main`
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force`
4. `php artisan optimize:clear && config:cache && route:cache && view:cache`
5. `php artisan queue:restart`
6. Health gate: Redis ping, queue lengths, supervisor GPS workers, `gps:ingest-health --fast`
7. Exit code 2 if CRITICAL GPS issues; 0 if OK

**Post-deploy worker repair:**
```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start gps-processing:* gps-broadcast:* gps-side-effects:*
```

**Known deploy hazards:**
- `gps:ingest-health --deep` can OOM on small VPS (full `p_future` scan)
- Too many supervisor workers cause STARTING/EXITED flapping
- Path drift if Reverb/cron still point to `/home/api/public_html`

---

## 9. Staging Rebuild Checklist

To rebuild staging exactly:

1. **OS:** Ubuntu 24.04 LTS, 4 vCPU, 8 GB RAM minimum for GPS load testing
2. **Stack:** nginx, PHP 8.3-FPM, MariaDB 10.6, Redis 7, Supervisor
3. **Clone:** `api.pistatapp.ir/public_html` at matching git SHA
4. **Copy:** `.env` template with staging credentials (fix debug/telescope)
5. **Import:** `api_db` schema from `DATABASE_SCHEMA_REFERENCE.md` + selective data
6. **Supervisor:** Install 4 conf files from `deploy/supervisor/`
7. **Cron:** Fix scheduler path to domain `public_html`
8. **Reverb:** Align systemd/supervisor paths to single deploy root
9. **Run:** `deploy.sh` health gate before enabling GPS replay

---

## 10. Related Documents

| Document | Contents |
|---|---|
| `DATABASE_SCHEMA_REFERENCE.md` | Full DDL, FK map, table inventory |
| `GPS_PIPELINE_REFERENCE.md` | End-to-end GPS data flow |
| `MIGRATION_RISKS.md` | Staging/production migration risks, PostGIS strategy |
| `PISTAT_GPS_AUDIT.md` | Prior GPS safety audit (2026-07-30) |
| `deploy/supervisor/GPS-WORKERS.md` | Worker ops runbook |

---

*Generated by production architecture audit — read-only, 2026-08-04*
