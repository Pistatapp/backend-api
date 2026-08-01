# GPS ingest workers — ops checklist (PiStat)

## Symptom
Live map markers may move (or appear to), but `gps_data` has no rows for "today"
and historical path polyline is empty.

## Root causes seen in production (2026-08-01)
1. **No `gps-processing` / `gps-broadcast` supervisor programs** (`supervisorctl status | grep gps` empty).
2. Supervisor conf pointed at **wrong path** (`/home/api/public_html` vs `/home/api/domains/api.pistatapp.ir/public_html`).
3. **Redis `LOADING`** — queue dispatch/work fails until Redis finishes loading RDB/AOF.
4. Device clocks stuck in the past (e.g. 2026-07-30) or far future (e.g. 2068) — fixed in `IngestGpsData::normalizeDeviceDateTime`.
5. **Too many workers on eco1-small** (e.g. 32 processing + 16 broadcast) → many `STARTING` / occasional `EXITED` after `queue:restart`.
6. **`[group:…]` wrappers** — `supervisorctl start gps-processing:*` fails with `ERROR (no such group)`;
   processes appear as `gps-ingest:gps-processing_00` / `gps-broadcast-group:gps-broadcast_00`.
   Prefer confs **without** `[group:]` (current repo). Until then start the group name.

## Recommended numprocs (eco1-small)
| Program | numprocs |
|---|---|
| `gps-processing` | **4** |
| `gps-broadcast` | **2** |
| `gps-side-effects` | **2** |
| `gps-side-effects-consumer` | **1** |

Raise only if CPU/RAM allow and queues backlog under load.

## Symlink note
On some hosts `/etc/supervisor/conf.d/laravel-gps-workers.conf` is a **symlink** into
`public_html/deploy/supervisor/…`. Then `cp` says "are the same file" — that is OK.
After `git pull`, run `sudo supervisorctl reread && sudo supervisorctl update`.

## Scale down / repair workers
```bash
cd /home/api/domains/api.pistatapp.ir/public_html

# If NOT a symlink, copy; if symlink, pull is enough:
sudo cp -f deploy/supervisor/laravel-gps-workers.conf /etc/supervisor/conf.d/ 2>/dev/null || true
sudo cp -f deploy/supervisor/laravel-gps-broadcast.conf /etc/supervisor/conf.d/ 2>/dev/null || true
sudo cp -f deploy/supervisor/laravel-gps-side-effects.conf /etc/supervisor/conf.d/ 2>/dev/null || true
sudo cp -f deploy/supervisor/laravel-gps-side-effects-consumer.conf /etc/supervisor/conf.d/ 2>/dev/null || true

sudo supervisorctl reread
sudo supervisorctl update

# Preferred (no [group:] in conf):
sudo supervisorctl start gps-processing:* gps-broadcast:* gps-side-effects:* gps-side-effects-consumer:*

# Legacy group names (if status shows gps-ingest: / gps-broadcast-group:):
sudo supervisorctl start gps-ingest:* gps-broadcast-group:* gps-side-effects-group:* gps-side-effects-consumer-group:*

sudo supervisorctl status | grep gps
```

Healthy eco1-small example:
```
gps-processing:gps-processing_00..03     RUNNING   (4)
gps-broadcast:gps-broadcast_00..01       RUNNING   (2)
gps-side-effects:…_00..01               RUNNING   (2)
gps-side-effects-consumer:…_00           RUNNING   (1)
```

Legacy but also OK:
```
gps-ingest:gps-processing_00..03
gps-broadcast-group:gps-broadcast_00..01
gps-side-effects-group:… (2)
gps-side-effects-consumer-group:… (1)
```

## After deploy of PHP code
```bash
./deploy.sh
# or:
php artisan queue:restart
php artisan gps:ingest-health
```

## Purge far-future junk clocks (optional — can be heavy on p_future)
```bash
# Prefer dedicated purge dry-run over gps:ingest-health --deep on eco1-small
php artisan gps:purge-future-junk --dry-run
php artisan gps:purge-future-junk --force
```

## Why SSH drops mid-deploy?
Full `COUNT(*)` of far-future rows on `gps_data` scans partition `p_future` (often 10M+ mixed
dates). On a small VPS that can spike RAM/CPU, freeze the host, and SSH ends with
`Connection closed by remote host`. `deploy.sh` now runs `gps:ingest-health --fast` to avoid that.

## Redis must be ready before replay from gateway
```bash
redis-cli ping          # must be PONG (not LOADING)
redis-cli LLEN queues:gps-processing
```

## Verify writes after gateway replay
```sql
SELECT COUNT(*), MIN(date_time), MAX(date_time)
FROM gps_data
WHERE tractor_id = 38 AND date_time >= CURDATE();
```
