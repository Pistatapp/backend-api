# GPS Ingest Service

High-throughput Go service that handles `POST /api/gps/reports` for tractor GPS devices.

## Features

- IP allowlist enforcement
- Request validation matching Laravel `GpsReportRequest`
- Async ingest pipeline with worker pools and batch MySQL writes
- Two-layer IMEI cache (in-process + Redis + MySQL fallback)
- Direct Reverb/Pusher broadcasts
- Async tractor status updates and Redis side-effect inbox for Laravel task logic

## Quick start

```bash
cd services/gps-ingest
cp ../../.env .env   # or export env vars
make tidy
make test
make build
./bin/gps-ingest
```

## Endpoints

| Endpoint | Description |
|----------|-------------|
| `POST /api/gps/reports` | GPS ingest |
| `GET /healthz` | Health check |
| `GET /metrics` | Prometheus-style metrics |

## Key environment variables

Uses the same `.env` as Laravel for database, Redis, Reverb, and GPS allowlist settings.

Additional tuning:

- `GPS_INGEST_HTTP_ADDR` (default `:8081`)
- `INGEST_WORKERS` (default `64`)
- `INGEST_CHANNEL_SIZE` (default `100000`)
- `BATCH_FLUSH_INTERVAL_MS` (default `50`)
- `BATCH_FLUSH_SIZE` (default `1000`)

Integration tests:

- `GPS_TEST_DSN` — optional MySQL DSN for `writer_integration_test.go` (skipped if unset)

## Deployment

**Start here:** [`docs/gps-ingest-deployment.md`](../../docs/gps-ingest-deployment.md) — full production deployment guide (agent-friendly step-by-step).

Supporting docs:

- `deploy/gps-ingest.service` — systemd unit
- `deploy/nginx-gps-shadow.conf` — Phase 5 shadow mode (mirror to Laravel)
- `deploy/nginx-gps-production.conf` — Phase 6 production cutover
- `deploy/nginx-gps.conf` — legacy combined snippet
- `docs/gps-ingest-rollback-runbook.md` — rollback procedure
- `docs/gps-ingest-shadow-validation.md` — Phase 5 daily checklist
- `docs/gps-ingest-monitoring.md` — metrics and alerting guide

## Laravel integration

- `GPS_INGEST_DRIVER=go` delegates from `GpsReportController` to this service
- Production routing uses nginx to send `/api/gps/reports` directly to Go
- `php artisan gps:consume-side-effects` consumes `gps_side_effects_inbox`
