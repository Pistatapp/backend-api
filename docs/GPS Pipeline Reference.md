# GPS Pipeline Reference

**System:** PiStat Production (`api.pistatapp.ir`)  
**Audit date:** 2026-08-04  
**Current ingest driver:** `laravel` (`GPS_INGEST_DRIVER=laravel`)

---

## 1. End-to-End Data Flow

```
┌─────────────────┐
│  GPS Device     │  Tractor-mounted GPS tracker (IMEI-bound)
│  (Hardware)     │
└────────┬────────┘
         │ Raw NMEA / proprietary frames
         ▼
┌─────────────────┐
│  IoT Gateway    │  Aggregates batches, POSTs JSON
│  (94.101.187.206│  Source IP allowlisted
│   + others)     │
└────────┬────────┘
         │ POST /api/gps/reports
         │ Content-Type: application/json
         │ Body: { "data": [ { imei, coordinate, date_time, speed, status, directions } ] }
         ▼
┌─────────────────┐
│  nginx          │  api.pistatapp.ir:443
│  (Reverse proxy)│  Production cutover: proxy to Go :8081 (when enabled)
└────────┬────────┘  Current: Laravel PHP-FPM path
         ▼
┌─────────────────┐
│  Laravel API    │  Middleware: gps.ingest
│  GpsReportController│  • EnsureGpsIngestAllowed (IP allowlist → 403)
│                 │  • GpsReportRequest validation → 422
│                 │  • GPS_INGEST_DRIVER=laravel → dispatch job
│                 │  • GPS_INGEST_DRIVER=go → HTTP proxy to Go service
└────────┬────────┘
         │ IngestGpsData::dispatch($data, $traceId)
         │ Redis LPUSH queues:gps-processing
         ▼
┌─────────────────┐
│  Redis Queue    │  queues:gps-processing
│  (DB 0)         │  Prefix: {app}_database_
└────────┬────────┘
         │ 4× Supervisor workers: queue:work --queue=gps-processing
         ▼
┌─────────────────┐
│  IngestGpsData  │  Job (ShouldQueue)
│  Worker         │  • WithoutOverlapping(imei) releaseAfter(3s)
│                 │  • Resolve tractor via Cache + GpsDeviceCache
│                 │  • normalizeDeviceDateTime (clock skew fix)
│                 │  • Bulk insertOrIgnore → mysql_gps.gps_data
│                 │  • On success → BroadcastGpsEvents::dispatch()
└────────┬────────┘
         │
    ┌────┴────┐
    ▼         ▼
┌────────┐ ┌──────────────────┐
│ MariaDB│ │ Redis Queue       │
│ gps_data│ │ queues:gps-broadcast│
│ (api_db)│ └────────┬─────────┘
└────────┘          │ 2× workers
                    ▼
           ┌─────────────────┐
           │ BroadcastGpsEvents│
           │                 │  • event(TractorStatus)
           │                 │  • event(ReportReceived)
           │                 │  • UpdateTractorStatusJob → gps-side-effects
           └────────┬────────┘
                    │
         ┌──────────┴──────────┐
         ▼                     ▼
┌─────────────────┐   ┌─────────────────┐
│ Laravel Reverb  │   │ gps-side-effects │
│ ws.pistatapp.ir │   │ queue (2 workers)│
│ :443 / WSS      │   │ ReportReceived   │
│ Port 8088 bind  │   │ Listener → task  │
└────────┬────────┘   │ zone detection   │
         │            └─────────────────┘
         │ Private channel: gps_devices.{device_id}
         │ Event: report-received
         ▼
┌─────────────────┐
│  Mobile App     │  Android (immutable contract)
│  (Kotlin)       │  Live map marker updates
└─────────────────┘

Historical path (separate read path):
  GET /api/tractors/{id}/path → TractorPathStreamService → mysql_gps_read
```

---

## 2. Services & Processes

| Service | Process | Path | Port | User |
|---|---|---|---:|---|
| nginx | `nginx` | system | 80/443 | www-data |
| PHP-FPM | `php-fpm83` | system | unix socket | api |
| Laravel workers | `supervisor → queue:work` | domain public_html | — | api |
| Laravel Reverb | `supervisor → reverb:start` | **legacy** public_html | 8088 | api |
| Go gps-ingest | `systemd gps-ingest.service` | **legacy** public_html | 8081 | api |
| Redis | `redis-server` | system | 6379 (localhost) | redis |
| MariaDB | `mysqld` | system | 3306 | mysql |

---

## 3. Queue Names & Workers

| Queue Name | Redis Key | Workers | numprocs | Command |
|---|---|---:|---:|---|
| `gps-processing` | `queues:gps-processing` | Supervisor | **4** | `queue:work redis --queue=gps-processing --tries=3 --timeout=90` |
| `gps-broadcast` | `queues:gps-broadcast` | Supervisor | **2** | `queue:work redis --queue=gps-broadcast --tries=3 --timeout=60` |
| `gps-side-effects` | `queues:gps-side-effects` | Supervisor | **2** | `queue:work redis --queue=gps-side-effects --tries=3 --timeout=90` |
| `default` | `queues:default` | Supervisor | **2** | `queue:work redis --queue=default --tries=3` |
| *(failed)* | — | DB table | — | `failed_jobs` (21 at audit) |

**Side-effect consumer (non-Laravel-queue):**

| Process | Command | numprocs |
|---|---|---:|
| `gps-side-effects-consumer` | `gps:consume-side-effects --timeout=5` | **1** |

---

## 4. Job Classes & Retry Behavior

### 4.1 IngestGpsData (`gps-processing`)

| Property | Value |
|---|---|
| Class | `App\Jobs\IngestGpsData` |
| `$tries` | 3 |
| `$maxExceptions` | 2 |
| `$timeout` | 60s (worker: 90s) |
| `$backoff` | [2, 5, 10] seconds |
| Middleware | `WithoutOverlapping(imei)` → releaseAfter(3s) |
| Connection | `redis` |
| After commit | true |

**Failure handling:**
- Unbound IMEI → log warning, NOC emit, **discard batch** (no retry)
- Empty prepared batch → discard
- Zero rows persisted → **throw RuntimeException** → job retry
- DB chunk failure → row-by-row recovery
- Stale MySQL connection → reconnect up to 3 attempts per row
- Partition miss → rewrite `date_time` to server now, retry
- Duplicate `(imei, date_time)` → `insertOrIgnore` (counts as success)

### 4.2 BroadcastGpsEvents (`gps-broadcast`)

| Property | Value |
|---|---|
| Class | `App\Jobs\BroadcastGpsEvents` |
| `$tries` | 3 |
| `$timeout` | 30s (worker: 60s) |
| Dispatched | Only after durable DB write |

**Failure handling:**
- Missing tractor/device → NOC emit, return (no retry needed)
- WS broadcast failure → job retry (3×)

### 4.3 UpdateTractorStatusJob (`gps-side-effects`)

| Property | Value |
|---|---|
| Class | `App\Jobs\UpdateTractorStatusJob` |
| Queue | `gps-side-effects` |
| Purpose | Update tractor `is_working` status |

### 4.4 ReportReceivedListener (`gps-side-effects`)

| Property | Value |
|---|---|
| Class | `App\Listeners\ReportReceivedListener` |
| Implements | `ShouldQueue`, `ShouldQueueAfterCommit` |
| `$tries` | 3 |
| Purpose | Tractor task zone entry/exit detection |

---

## 5. Redis Key Patterns

| Key Pattern | Type | TTL | Purpose |
|---|---|---:|---|
| `queues:gps-processing` | List | — | Ingest job queue |
| `queues:gps-broadcast` | List | — | Broadcast job queue |
| `queues:gps-side-effects` | List | — | Side-effect job queue |
| `queues:default` | List | — | General job queue |
| `gps:device:{imei}` | String (JSON) | 3600s | `{tractor_id, device_id}` mapping (Go + Laravel) |
| `tractor_by_device_imei_{imei}` | Cache | 3600s | Laravel Cache tractor resolution |
| `gps_side_effects_inbox` | List | — | Go → Laravel side-effect bridge (BLPOP) |
| `gps:monitor:events` | Pub/Sub channel | — | NOC monitor (optional Redis driver) |
| `{prefix}horizon:*` | Various | — | Horizon metadata (if enabled) |
| `{prefix}cache:*` | String | varies | Laravel cache (DB 1) |

**Laravel Redis prefix:** `{APP_NAME_slug}_database_` (from `REDIS_PREFIX`)

**Queue backlog at audit:** 0 on all queues

---

## 6. HTTP Ingest Contract

### Endpoint

```
POST /api/gps/reports
```

- **Middleware group:** `gps.ingest` (no `throttle:api`)
- **Auth:** IP allowlist only (`GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS`)
- **CSRF:** Excluded (`api/gps/reports`)

### Request validation (`GpsReportRequest`)

Required per frame in `data[]`:
- `imei` (string)
- `coordinate` (array[2]: lat, lon)
- `date_time` (datetime string)
- `speed` (integer ≥ 0)
- `status` (0 or 1)
- `directions.ew`, `directions.ns`

### Responses

| Code | Body | Meaning |
|---:|---|---|
| 200 | `{"success": true}` | Queued (Laravel) or accepted (Go) |
| 403 | `{"message": "Forbidden."}` | IP not allowlisted |
| 422 | Validation errors | Malformed payload |

### Clock normalization (IngestGpsData)

| Rule | Action |
|---|---|
| Empty/unparseable date_time | Server now |
| Before 2025-01-01 | Server now |
| > 36h in past | Server now |
| > 24h in future | Server now |
| Same-second collisions | Spatial nearest-neighbor + consecutive seconds |

---

## 7. Database Write Path

**Connection:** `mysql_gps` (same `api_db` database on production)  
**Table:** `gps_data`  
**Insert columns:** `tractor_id`, `coordinate`, `speed`, `status`, `directions`, `imei`, `date_time`  
**Batch size:** 200 rows per chunk  
**Idempotency:** `insertOrIgnore` when unique index exists; plain insert otherwise

**Read path:** `mysql_gps_read` connection for path streaming (same host currently)

---

## 8. Realtime Broadcast

### Events

| Event | Channel | Event name | Payload |
|---|---|---|---|
| `ReportReceived` | `private-gps_devices.{id}` | `report-received` | lat, lon, speed, status, directions, Jalali date_time |
| `TractorStatus` | `private-tractor.{id}` | `tractor.status.changed` | tractor, status |

### Reverb client config (mobile)

| Setting | Value |
|---|---|
| Host | `ws.pistatapp.ir` |
| Port | 443 |
| Scheme | HTTPS/WSS |
| App key | From `REVERB_APP_KEY` (env) |

### Channel authorization

```php
Broadcast::channel('gps_devices.{gps_device}', fn ($user, $device) => true);
```

> **Note:** All authenticated users can subscribe to any GPS device channel.

---

## 9. Go Ingest Service (Standby / Cutover Path)

**Binary:** `/home/api/public_html/services/gps-ingest/bin/gps-ingest`  
**systemd:** `gps-ingest.service` (User: api)  
**Default port:** `:8081` (config: `GPS_INGEST_HTTP_ADDR`)

### Go pipeline internals

| Stage | Workers (default config) | Channel size |
|---|---:|---:|
| Ingest | 64 (`INGEST_WORKERS`) | 100,000 |
| Broadcast | 32 | 50,000 |
| Side effects | 16 | 50,000 |
| Batch flush | 1 | 1000 rows / 50ms |

### Go → Laravel bridge

After Go broadcast, side effects published to Redis list:
```
gps_side_effects_inbox  →  gps:consume-side-effects  →  ReportReceivedListener
```

### nginx cutover (when enabled)

```nginx
location = /api/gps/reports {
    proxy_pass http://127.0.0.1:8081;
}
```

When Go handles ingest, Laravel `gps-processing` and `gps-broadcast` workers can be stopped.

---

## 10. NOC Monitoring Side-Channel

**Service:** `NocMonitor`  
**Driver:** HTTP (`NOC_MONITOR_URL=http://127.0.0.1:3200`)  
**Events:** `PISTAT_DELIVERY`, `MAP_DELIVERED`  
**Phases:** `queued`, `persist`, `persisted`, `app_ws`, `drop`, `error`

Does not mutate GPS payloads — observability only.

---

## 11. Secondary GPS Paths

### Labour attendance GPS

```
Mobile phone → POST /api/attendance/gps-report → attendance_gps_data
```

Separate from tractor gateway ingest. Uses explicit `latitude`/`longitude` fields.

### Daily GPS metrics

```
Scheduler 23:00 → CalculateGpsMetricsJob (default queue) → gps_metrics_calculations
```

Reads `gps_data` for each tractor, computes daily work/stoppage metrics.

### Path API (historical)

```
GET /api/tractors/{tractor}/path → TractorPathStreamService
  → mysql_gps_read SELECT (tractor_id, date range)
  → GpsPathCorrectorService (optional Kalman/median)
  → JSON stream to mobile
```

---

## 12. Operational Commands

```bash
# Health check (safe for production)
php artisan gps:ingest-health --fast

# Queue depths
redis-cli LLEN queues:gps-processing
redis-cli LLEN queues:gps-broadcast
redis-cli LLEN queues:gps-side-effects

# Worker status
sudo supervisorctl status | grep gps

# Diagnose persist for tractor
php artisan gps:diagnose-persist --tractor=38

# Verify today's writes
# SELECT COUNT(*), MIN(date_time), MAX(date_time)
# FROM gps_data WHERE tractor_id = ? AND date_time >= CURDATE();

# After code deploy
php artisan queue:restart
./deploy.sh
```

---

## 13. Failure Mode Matrix

| Symptom | Likely cause | Fix |
|---|---|---|
| Map moves, path empty | No gps-processing workers | Start supervisor workers |
| Map frozen, DB growing | No gps-broadcast workers | Start gps-broadcast:* |
| 403 from gateway | IP not in allowlist | Update `GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS` |
| Queue backlog > 5000 | Under-provisioned workers | Scale workers or enable Go ingest |
| Redis LOADING | Redis restart/RDB load | Wait for PONG |
| Rows in far-future dates | Bad device RTC | `gps:purge-future-junk` |
| INSERT blocked, WS works | Partition REORGANIZE lock | Never run `gps:ensure-partitions` online |
| Path empty for "today" | Stuck device clock in past | Fixed in IngestGpsData normalizeDeviceDateTime |
| Task zones not updating | gps-side-effects down | Start side-effect workers + consumer |

---

## 14. Worker Sizing (eco1-small reference)

| Program | Recommended | Production (audit) | Legacy conf (disabled) |
|---|---:|---:|---:|
| gps-processing | 4 | 4 | 32 |
| gps-broadcast | 2 | 2 | 16 |
| gps-side-effects | 2 | 2 | 8 |
| gps-side-effects-consumer | 1 | 1 | 1 |

---

*Generated by production architecture audit — read-only, 2026-08-04*
