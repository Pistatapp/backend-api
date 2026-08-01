# GPS ingest workers — ops checklist (PiStat)

## Symptom
Live map markers may move (or appear to), but `gps_data` has no rows for "today"
and historical path polyline is empty.

## Root causes seen in production (2026-08-01)
1. **No `gps-processing` / `gps-broadcast` supervisor programs** (`supervisorctl status | grep gps` empty).
2. Supervisor conf pointed at **wrong path** (`/home/api/public_html` vs `/home/api/domains/api.pistatapp.ir/public_html`).
3. **Redis `LOADING`** — queue dispatch/work fails until Redis finishes loading RDB/AOF.
4. Device clocks stuck in the past (e.g. 2026-07-30) or far future (e.g. 2068) — fixed in `IngestGpsData::normalizeDeviceDateTime`.

## Install / repair workers
```bash
cd /home/api/domains/api.pistatapp.ir/public_html

sudo cp deploy/supervisor/laravel-gps-workers.conf /etc/supervisor/conf.d/
sudo cp deploy/supervisor/laravel-gps-broadcast.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start gps-processing:*
sudo supervisorctl start gps-broadcast:*
sudo supervisorctl status | grep gps
```

Expected: several `RUNNING` lines for `gps-processing_00..` and `gps-broadcast_00..`.

## After deploy of PHP code
```bash
php artisan queue:restart
php artisan gps:ingest-health
```

## Redis must be ready before replay from gateway
```bash
redis-cli ping          # must be PONG (not LOADING)
redis-cli LLEN queues:gps-processing
redis-cli info persistence | head
```

If `LOADING`, wait until `redis-cli ping` returns `PONG`, then start workers.

## Verify writes after gateway replay
```sql
SELECT COUNT(*), MIN(date_time), MAX(date_time)
FROM gps_data
WHERE tractor_id = 38 AND date_time >= CURDATE();
```

Also reject garbage future clocks in app path queries is handled by ingest resync
(>24h future → server now).
