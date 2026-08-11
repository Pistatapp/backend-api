# Phase 12 — Production Infrastructure Audit

**Host:** `ubuntu-eco1-small1-bamdad-1`  
**Primary API:** `api.pistatapp.ir` (`188.121.119.99`)  
**WebSocket:** `ws.pistatapp.ir`  
**Audit date:** 2026-08-05  
**Mode:** READ-ONLY — no modifications applied  
**Audited against:** `docs/PiStat Production Architecture Report.md`, `docs/GPS Pipeline Reference.md`, `docs/Migration Risks & Recommendations.md`

---

## Executive Snapshot

| Area | Status | Notes |
|---|---|---|
| OS / capacity | ⚠ At risk | Disk **89%**, **no swap** |
| Web stack | OK (running) | nginx 1.29.6 + PHP-FPM 8.3 + Apache (DA) |
| Workers / Reverb | OK (running) | GPS workers 4/2/2/1 + Reverb up |
| Redis | OK | 7.0.15, localhost, queues empty |
| MariaDB | ⚠ | 10.6.25, bind `0.0.0.0`, buffer pool 128 MB |
| Docker | Idle | Engine installed; **0 containers** |
| Deploy method | Bare-metal | `deploy.sh` + git hard-reset (not Docker) |
| Path drift | Partially mitigated | `/home/api/public_html` → symlink to domain tree |
| Security | ❌ Blockers | World-readable `.env`, `APP_DEBUG=true`, MySQL exposed, SSH password auth |

---

## 1. OS / Hardware

| Attribute | Live value (2026-08-05) |
|---|---|
| OS | Ubuntu 24.04.4 LTS (Noble) |
| Kernel | 6.8.0-136-generic |
| Hypervisor | KVM (Intel Xeon Cascadelake) |
| vCPUs | 4 |
| RAM | 7953 MB total; ~2581 MB used; ~5371 MB available |
| Swap | **0 (none configured)** |
| Root disk `/dev/vda3` | 66 GB total, **56 GB used (89%)**, 7.0 GB free |
| Load average | 0.27 / 0.19 / 0.08 |
| Uptime | ~16 days |

**Blocker:** Disk at 89% with continuous `gps_data` growth (~5–7 MB/day) and Telescope enabled. No swap → OOM risk on large queries / partition ops.

---

## 2. Docker Status

| Check | Result |
|---|---|
| Docker Engine | Installed (Client 29.5.3, Compose v5.1.4) |
| `docker ps -a` (via sudo) | **Empty** — no running/stopped app containers |
| `docker0` interface | Present (`172.17.0.1/16`) |
| App deployment via Docker | **No** |

**Conclusion:** Production is **not** Docker-deployed. Staging target that assumes Docker Compose for the API would diverge unless explicitly chosen as a new architecture (out of scope for parity migration).

---

## 3. Current Deployment Method

| Item | Value |
|---|---|
| Canonical app path | `/home/api/domains/api.pistatapp.ir/public_html` |
| Legacy path | `/home/api/public_html` → **symlink** to domain `public_html` |
| Deploy script | `./deploy.sh` (`DEPLOY_BRANCH=main`) |
| Mechanism | `git fetch` + `git reset --hard origin/main` → `composer install` → `migrate --force` → caches → `queue:restart` → GPS health gate |
| Process manager | Supervisor (queue/Reverb) + systemd (`gps-ingest`, `php-fpm83`, `nginx`, `mysqld`, `redis`) |
| DirectAdmin | Present (httpd, named, mail, CSF/LFD) |

**Path drift update vs Architecture Report:** Symlink now unifies filesystem content, but **config references remain mixed** (supervisor Reverb/legacy confs still cite `/home/api/public_html`; GPS workers cite domain path). Symlink reduces code-version skew risk; does not remove duplicate supervisor program definitions.

---

## 4. nginx

| Attribute | Value |
|---|---|
| Version | nginx/1.29.6 |
| Service | `nginx.service` **active** |
| Public listen | `188.121.119.99:80`, `:443` |
| Local listen | `127.0.0.1:80/443` |
| Config readability | `/etc/nginx/` **permission denied** for audit user (`nginx -T` failed) |
| GPS cutover snippet (repo) | `services/gps-ingest/deploy/nginx-gps-production.conf` proxies `/api/gps/reports` → `127.0.0.1:8081` |

**Live HTTP probe (read-only HEAD/OPTIONS, no GPS payload):**

- `HEAD/OPTIONS https://api.pistatapp.ir/api/gps/reports` → **HTTP/2 405** via `server: nginx`
- Endpoint exists; method restriction expected for non-POST

**Gateway compatibility note:** Live Go ingest listens on **`127.0.0.1:8082`** (`GPS_INGEST_HTTP_ADDR=127.0.0.1:8082`). Production cutover nginx snippet targets **`:8081`**. Ports **8080/8081** are listening on `*` (Apache/`httpd` stack). Cutover using the repo snippet **as-is would miss Go** (blocker for Go cutover phase; Laravel driver currently active).

---

## 5. PHP-FPM

| Attribute | Value |
|---|---|
| Service | `php-fpm83.service` **active** (since 2026-08-01) |
| PHP | 8.3.30 (CLI NTS) |
| Config | `/usr/local/php83/etc/php-fpm.conf` |
| Pool model | DirectAdmin user pools; `pm = ondemand`, `pm.max_children = 10` |
| Listen | `/usr/local/php83/sockets/$pool.sock` |
| Snapshot status | `Processes active: 0, idle: 0`, Requests: 7109, slow: 0 |

**Note:** Zero idle workers at snapshot is consistent with ondemand + low traffic; not necessarily a fault. Staging should match PHP 8.3 + ondemand/DA pool semantics or explicitly document divergence.

---

## 6. Supervisor Workers

### Live status (2026-08-05)

| Program | Count | Status | Command path |
|---|---:|---|---|
| `gps-processing` | 4 | RUNNING | domain `public_html` |
| `gps-broadcast` | 2 | RUNNING | domain `public_html` |
| `gps-side-effects` | 2 | RUNNING | domain `public_html` |
| `gps-side-effects-consumer` | 1 | RUNNING | domain `public_html` (~18h uptime) |
| `laravel-worker` (default) | 2 | RUNNING | domain `public_html` |
| `laravel-reverb` | 1 | RUNNING | `/home/api/public_html/artisan reverb:start` (~4d uptime) |

Queue depths (Redis): all GPS + default queues **0**.

### Config topology issues (documented blockers for clean migrate/staging)

| Issue | Detail |
|---|---|
| Duplicate program defs | `gps-broadcast` defined in symlink `larave-gps-broadcast.conf` **and** `laravel-gps-broadcast.conf` |
| Duplicate side-effects | Same pattern for `gps-side-effects` |
| Legacy oversized cutover | `gps-go-cutover.conf` (`numprocs=32/16`, `autostart=false`, legacy paths) still present |
| Mixed symlink targets | Several confs still symlink under `/home/api/public_html/deploy/supervisor/` (resolves via symlink today) |

**Staging target topology (from Architecture Report / deploy/supervisor):** single clean set — processing **4**, broadcast **2**, side-effects **2**, consumer **1**, default **2**, Reverb **1** — no duplicates, one canonical path.

---

## 7. Redis

| Attribute | Live value |
|---|---|
| Version | 7.0.15 |
| Bind | `127.0.0.1 -::1` |
| Port | 6379 |
| Mode | standalone |
| Used memory | ~7.58 MB |
| Max memory | 1.00 GB (`allkeys-lru`) |
| Keyspace | db0: 53 keys; db1: 3 keys |
| Secondary instance | `redis@1002.service` active (DirectAdmin) |
| App usage | `QUEUE_CONNECTION=redis`, `CACHE_DRIVER=redis` |

Matches staging target (Redis 7, localhost-only).

---

## 8. MariaDB

| Attribute | Live value |
|---|---|
| Version | 10.6.25-MariaDB |
| Service | `mysqld.service` **active** |
| App DB | `api_db` @ `127.0.0.1:3306` |
| Network bind | **`0.0.0.0:3306`** (and `[::]:3306`) — **security blocker** |
| Total DB size | ~3.76–3.85 GB |
| Tables | 74 |
| `innodb_buffer_pool_size` | **134217728 (128 MB)** — undersized vs 3.6 GB `gps_data` |
| `max_connections` | 151 |
| Server charset default | **latin1** (tables mostly utf8mb4 — table-level override) |
| Event scheduler | **OFF**; no GPS partition events |

See `PHASE_12_DATABASE_AUDIT.md` for schema/partition detail.

---

## 9. Reverb / WebSocket

| Attribute | Live value |
|---|---|
| Process | Supervisor `laravel-reverb_00` RUNNING |
| Bind | `0.0.0.0:8088` (publicly reachable) |
| Client config | `REVERB_HOST=ws.pistatapp.ir`, port 443, scheme https |
| Driver | `BROADCAST_DRIVER=reverb` |
| Uptime | ~4 days |
| Allowed origins | `*` (per Architecture Report; not re-verified in conf due to nginx ACL) |

**Staging target:** Reverb on dedicated WS domain, restricted origins, bind preferably localhost + nginx TLS termination only.

---

## 10. Scheduler / Cron

| Job | User | Schedule | Path |
|---|---|---|---|
| `php artisan schedule:run` (flock) | **api** | `* * * * *` | `cd /home/api/public_html` (symlink OK) |
| `/opt/pistat-db-backup.sh` | root | `0 2,14 * * *` | system |
| `/opt/pistat-weekly-full.sh` | root | `0 3 * * 0` | system |
| `/usr/local/bin/gps_lag_check.sh` | root | `* * * * *` | system |

Laravel schedule (live `schedule:list`) includes farm/irrigation/tractor jobs, frost/pest/attendance, GPS metrics at 23:00, Telescope prune 24h. **`gps:ensure-partitions` is not scheduled** (correct — must stay DBA-only).

---

## 11. Go GPS Ingest (Standby)

| Attribute | Live value |
|---|---|
| systemd | `gps-ingest.service` **active** (since 2026-07-29) |
| Binary path | `/home/api/public_html/services/gps-ingest/bin/gps-ingest` |
| Listen | **`127.0.0.1:8082`** |
| Env | `GPS_INGEST_DRIVER=laravel` (Laravel path active) |
| EnvironmentFile | `/home/api/public_html/.env` |
| Recent logs | MySQL idle connection EOF warnings (Aug 2) |

---

## 12. Firewall / Exposure

| Control | Status |
|---|---|
| UFW | Not usable without root (prior report: inactive) |
| CSF / LFD | `lfd` + `csf` **active** |
| Public listeners of note | 22, 80/443, **3306**, **8088**, 8080/8081, mail ports |
| SSH | `PasswordAuthentication yes`, `PermitRootLogin yes`, `PubkeyAuthentication no` |

---

## 13. Non-Secret Application Environment

| Variable | Production |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | **`true`** ❌ |
| `TELESCOPE_ENABLED` | **`true`** ❌ |
| `GPS_INGEST_DRIVER` | `laravel` |
| `BROADCAST_DRIVER` | `reverb` |
| `QUEUE_CONNECTION` / `CACHE_DRIVER` | `redis` |
| `DB_DATABASE` | `api_db` |
| `.env` mode | **`755` / world-readable** ❌ owner `ubuntu:ubuntu` |
| `storage/` / `bootstrap/cache/` | **`777`** |

---

## 14. Production vs Staging Target Architecture

| Dimension | Production (current) | Staging target (parity) | Gap |
|---|---|---|---|
| OS | Ubuntu 24.04, 4 vCPU, 8 GB | Same minimum | Size disk ≥100 GB recommended |
| Deploy | Bare-metal `deploy.sh` | Same for parity | Do **not** require Docker for parity |
| App path | Domain tree + symlink legacy | **Single** canonical path | Clean supervisor/cron to one path |
| nginx | nginx 1.29 + DA httpd | nginx + PHP-FPM | Document Apache coexistence or omit on staging |
| PHP | 8.3.30 FPM ondemand | 8.3 FPM | Match |
| MariaDB | 10.6.25, bind 0.0.0.0, pool 128 MB | 10.6, **127.0.0.1**, sized buffer pool | **Must not copy** bind/exposure |
| Redis | 7.0.15 localhost | Redis 7 localhost | Match |
| Workers | 4/2/2/1 + default 2 + Reverb | Same topology, **no duplicate confs** | Deduplicate before staging install |
| Reverb | 0.0.0.0:8088, origins `*` | Localhost + restricted origins | Harden on staging |
| GPS driver | `laravel` (+ Go standby on 8082) | Start with `laravel` | Port 8081 vs 8082 mismatch if Go tested |
| Security | Debug/Telescope/.env 755 | Debug off, `.env` 640, no Telescope | **Do not replicate** prod insecure defaults |
| Disk/swap | 89%, no swap | Headroom + 2–4 GB swap | Capacity blocker on prod |

---

## 15. Infra Blockers (Modification Required — Not Applied)

1. Free disk space / expand volume (89% full).
2. Add swap (2–4 GB).
3. Restrict MySQL bind to `127.0.0.1`.
4. `chmod 640` + correct owner on `.env`; set `APP_DEBUG=false`; disable Telescope in production.
5. Deduplicate Supervisor GPS confs; align Reverb to single path explicitly.
6. Fix Go cutover port documentation/config (`8082` vs `8081`) before any Go enablement.
7. Raise `innodb_buffer_pool_size` under maintenance (capacity/perf).
8. SSH key-only auth / disable root password login.

---

*Phase 12 infrastructure audit — read-only, 2026-08-05*
