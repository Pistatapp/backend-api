# Phase 12 — Migration Risk Register

**Audit date:** 2026-08-05  
**Mode:** READ-ONLY documentation  
**Severity:** Critical / High / Medium / Low  
**Responsible phase:** suggested follow-on phase for remediation (no fixes in Phase 12)

---

## Legend

| Severity | Meaning |
|---|---|
| Critical | Outage, data loss, or breach likely if ignored before migration |
| High | Major functional / operational impact |
| Medium | Manageable with planning |
| Low | Minor; track in backlog |

---

## Risk Register

| ID | Risk | Severity | Impact | Mitigation (do not apply in Phase 12) | Responsible phase |
|---|---|---|---|---|---|
| R01 | Root disk **89%** full (7 GB free) | **Critical** | Backups fail; MySQL temp/binlog growth; GPS insert failures; host instability | Expand volume or purge Telescope/logs/old backups; GPS retention policy; staging ≥100 GB | Phase 13 — Capacity & Hardening |
| R02 | **No swap** configured | **Critical** | OOM killer on `gps_data` scans / deep health / partition ops | Add 2–4 GB swap; forbid full-table COUNT in automation | Phase 13 — Capacity & Hardening |
| R03 | MySQL listens on **`0.0.0.0:3306`** | **Critical** | Remote DB attack surface | Bind `127.0.0.1`; CSF confirm 3306 closed externally | Phase 13 — Security Hardening |
| R04 | `.env` mode **755** world-readable; owner `ubuntu` | **Critical** | Secret leak (DB, Reverb, SMS, payments) | `chmod 640`, owner `api:api`; rotate exposed secrets after | Phase 13 — Security Hardening |
| R05 | `APP_DEBUG=true` + `TELESCOPE_ENABLED=true` | **High** | Stack traces / PII in Telescope; disk growth | Set debug false; disable or auth-gate Telescope | Phase 13 — Security Hardening |
| R06 | SSH password auth + root login; pubkey disabled | **High** | Brute-force / credential compromise | Key-only SSH; disable root password | Phase 13 — Security Hardening |
| R07 | `gps_data` **p_future collapse** (~14.7M rows) | **Critical** | Path queries slow; partition REORGANIZE can block inserts for hours/days | Never schedule `gps:ensure-partitions` online; maintenance window only; staging use data subset | Phase 14 — DB Partition Strategy |
| R08 | Full-table / deep GPS scans OOM risk | **High** | Deploy/health scripts can take down host | Keep `--fast` health only; use `information_schema` estimates | Phase 13 / Deploy runbook |
| R09 | No unique `(imei, date_time)` on `gps_data` | **Medium** | Duplicate rows on gateway replay | Keep env flag false until offline dedupe; document staging parity | Phase 14 — DB Integrity |
| R10 | Schema assumption `gps_device_id` on `gps_data` | **High** | Broken ingest/path if code expects wrong column | Live column is **`tractor_id` only**; validate all migrate scripts/Go SQL | Phase 14 — Schema Freeze Validation |
| R11 | **13 tractor_gps** unbound (`tractor_id` NULL) | **High** | Those IMEIs discarded by ingest; empty paths for named tractors | Data cleanup: bind IMEI→tractor in controlled window; warm cache | Phase 15 — Device Binding Cleanup |
| R12 | 64 tractors without device row | **Medium** | Confusing fleet inventory; false “missing GPS” reports | Inventory active vs legacy tractors before migrate | Phase 15 — Device Binding Cleanup |
| R13 | Supervisor **duplicate** GPS program configs | **High** | Unpredictable `reread/update`; wrong numprocs | Install single conf set from `deploy/supervisor/`; remove duplicates/legacy cutover | Phase 13 — Worker Topology Cleanup |
| R14 | Path references mixed (symlink mitigates) | **Medium** | Future symlink removal breaks Reverb/cron/Go unit | Point all units to domain `public_html` explicitly | Phase 13 — Path Unification |
| R15 | Go cutover nginx **:8081** vs service **:8082** | **High** | Enabling cutover drops gateway posts | Align nginx proxy to `127.0.0.1:8082` or change listen; practice on staging | Phase 16 — Gateway / Go Cutover |
| R16 | Go standby running while driver=`laravel` | **Medium** | Confusion; accidental dual path; idle MySQL EOF noise | Document standby; only enable with nginx+driver change together | Phase 16 — Gateway / Go Cutover |
| R17 | Deploy method ≠ Docker | **Medium** | Staging Docker rebuild diverges from prod ops | Staging parity = bare-metal/`deploy.sh` unless architecture change approved | Phase 17 — Staging Parity Build |
| R18 | `innodb_buffer_pool_size` **128 MB** | **High** | Poor GPS read performance; pressure under load | Size ~50% RAM on DB-dedicated or raise carefully with monitoring | Phase 13 — Capacity & Hardening |
| R19 | Server charset **latin1** default | **Low** | Connection/default mismatch vs utf8mb4 tables | Set staging server utf8mb4; verify client charset | Phase 14 — Schema Freeze Validation |
| R20 | Reverb on `0.0.0.0:8088`, origins `*` | **Medium** | Unrestricted WS reachability | Localhost bind + nginx only; restrict origins | Phase 13 — Security Hardening |
| R21 | `storage/` / `bootstrap/cache/` mode **777** | **Medium** | Local privilege abuse | Tighten to 775/owned by `api` | Phase 13 — Security Hardening |
| R22 | Android contract drift in Pipeline **docs** | **Low** | Engineers may “fix” working WS channel | Correct docs to `tractor.status` public channel; do not change live event | Phase 12b — Doc Correction (optional) |
| R23 | Zero `gps_data` rows for audit day so far | **Medium** | May indicate gateway/fleet/bind issue before migrate | Ops verify gateway + bound IMEIs; do not send test packets in Phase 12 | Phase 15 — Pre-Migrate Ops Check |
| R24 | Failed jobs = 27 (frost/cold jobs) | **Low** | Non-GPS scheduler noise | Triage default-queue failures separately | Backlog |
| R25 | PostGIS / GIS migration complexity | **High** (future) | 18M row rewrite; dual-write complexity | Follow Migration Risks PostGIS phases; not blocking Laravel parity migrate | Future GIS phases |
| R26 | `queue:restart` during peak GPS | **Medium** | Temporary backlog | Deploy off-peak; settle timers in `deploy.sh` | Phase 17 — Deploy Rehearsal |
| R27 | nginx config not readable by audit user | **Low** | Incomplete live vhost confirmation | Root-level `nginx -T` in hardening phase | Phase 13 — Infra Access |

---

## Focus Area Rollup

### Database mismatch
- R07, R09, R10, R19 — **tractor_id** confirmed; unique absent; partitions collapsed.

### Disk capacity
- R01, R02, R08, R18 — **not ready** until capacity/swap addressed.

### Security
- R03–R06, R20, R21 — production insecure defaults must not be copied; fix before promoting broader access.

### Deployment differences
- R14, R17, R26, R27 — bare-metal `deploy.sh`; symlink hides some drift; Docker not in use.

### Worker topology
- R13, R14 — live counts OK (4/2/2/1) but conf duplication is a landmine.

### Gateway compatibility
- R15, R16, R23 — Laravel path OK; Go cutover port mismatch is a hard blocker for cutover.

### Android compatibility
- R22 only (doc). Frozen contracts match live code — see Android audit.

---

## Pre-Migration Gate (must be green)

- [ ] Disk &lt; 70% or volume expanded; swap present  
- [ ] MySQL bound to localhost; `.env` not world-readable; `APP_DEBUG=false`  
- [ ] Supervisor confs deduplicated  
- [ ] Device↔tractor bindings verified for active fleet  
- [ ] Go port alignment verified **before** any cutover  
- [ ] Staging built to bare-metal parity (not Docker unless approved)  
- [ ] Android frozen contracts re-checked on staging WS  

---

*Phase 12 risk register — read-only, 2026-08-05*
