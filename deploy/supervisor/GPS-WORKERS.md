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

## Recommended numprocs (eco1-small)
| Program | numprocs |
|---|---|
| `gps-processing` | **4** |
| `gps-broadcast` | **2** |
| `gps-side-effects` | **2** |
| `gps-side-effects-consumer` | **1** |

Raise only if CPU/RAM allow and queues backlog under load.

## Scale down / repair workers (do this if you see STARTING/EXITED storms)
```bash
cd /home/api/domains/api.pistatapp.ir/public_html

sudo cp deploy/supervisor/laravel-gps-workers.conf /etc/supervisor/conf.d/
sudo cp deploy/supervisor/laravel-gps-broadcast.conf /etc/supervisor/conf.d/
sudo cp deploy/supervisor/laravel-gps-side-effects.conf /etc/supervisor/conf.d/
sudo cp deploy/supervisor/laravel-gps-side-effects-consumer.conf /etc/supervisor/conf.d/

# Drop old high-numprocs program definitions cleanly
sudo supervisorctl stop gps-processing:* gps-broadcast:* gps-side-effects:* gps-side-effects-consumer:* || true
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start gps-processing:* gps-broadcast:* gps-side-effects:* gps-side-effects-consumer:*
sudo supervisorctl status | grep gps
```

Expected: a small number of `RUNNING` lines (about 4+2+2+1), not 32+16.

## After deploy of PHP code
```bash
./deploy.sh
# or:
php artisan queue:restart
php artisan gps:ingest-health
```

## Purge far-future junk clocks (optional)
```bash
php artisan gps:purge-future-junk --dry-run
php artisan gps:purge-future-junk --force
```

## Redis must be ready before replay from gateway
```bash
redis-cli ping          # must be PONG (not LOADING)
redis-cli LLEN queues:gps-processing
```

If `LOADING`, wait until `redis-cli ping` returns `PONG`, then start workers.

## Verify writes after gateway replay
```sql
SELECT COUNT(*), MIN(date_time), MAX(date_time)
FROM gps_data
WHERE tractor_id = 38 AND date_time >= CURDATE();
```
