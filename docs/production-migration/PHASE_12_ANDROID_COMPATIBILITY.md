# Phase 12 — Android Compatibility Audit

**Scope:** Frozen mobile contracts vs live production API/code  
**Audit date:** 2026-08-05  
**Mode:** READ-ONLY — no code or config changes  
**Sources:** live routes/events, `docs/GPS Pipeline Reference.md`, feature tests

**Rule for this document:** only report **differences** (and confirmations where needed to state “no difference”).

---

## 1. HTTP — `POST /api/gps/reports`

### Expected (frozen)

| Item | Contract |
|---|---|
| Method/path | `POST /api/gps/reports` |
| Auth | IP allowlist (`gps.ingest`), not Sanctum |
| Body | `{ "data": [ { imei, coordinate[2], date_time, speed, status, directions.ew/ns } ] }` |
| Success | **200** `{ "success": true }` |
| Forbidden | **403** |
| Validation | **422** |

### Live verification

| Check | Result |
|---|---|
| Route registered | ✅ `POST api/gps/reports` → `GpsReportController` (`gps.reports`) |
| Response on success | ✅ `response()->json(['success' => true], 200)` |
| Validation rules | ✅ Matches GpsReportRequest (imei, coordinate size 2, date_time, speed≥0, status 0/1, directions) |
| Driver | ✅ `GPS_INGEST_DRIVER=laravel` (queues ingest; same 200 contract) |
| Non-POST probe | `OPTIONS/HEAD` → **405** (expected; not a contract break) |

### Differences

**None** against the frozen HTTP success/error contract.

> Note (ops, not Android contract): Go nginx cutover snippet targets `:8081` while Go listens on `:8082`. Irrelevant while driver=`laravel` and nginx still hits PHP. Would become a gateway outage risk if cutover were enabled without port fix — see Risk Register.

---

## 2. WebSocket — Channel & Events

### Expected (frozen checklist from Phase 12 brief)

| Item | Expected |
|---|---|
| Device channel | `private-gps_devices.{id}` |
| Point event | `report-received` |
| Status channel | `tractor.status` |
| Status event | `tractor.status.changed` |

### Live code

| Item | Implementation | Match? |
|---|---|---|
| Channel | `PrivateChannel('gps_devices.' . $device->id)` → client `private-gps_devices.{id}` | ✅ |
| Event name | `ReportReceived::broadcastAs()` → `report-received` | ✅ |
| Payload | lat/lon, speed, status, directions, Jalali `date_time`, stoppage flags | ✅ (presentation layer) |
| Auth channel | `Broadcast::channel('gps_devices.{gps_device}', fn => true)` | ✅ (any auth user) |
| Status channel | `Channel('tractor.status')` — **public** channel `tractor.status` | ✅ vs Phase 12 brief |
| Status event | `tractor.status.changed` | ✅ |
| Status payload | `{ tractor: id, status: int }` | ✅ |

### Differences vs GPS Pipeline Reference.md (doc drift only)

| Reference doc says | Live code | Difference? |
|---|---|---|
| `TractorStatus` on `private-tractor.{id}` | Public `tractor.status` | **Yes — documentation mismatch** |
| Event `tractor.status.changed` | Same | No |

**Android impact:** If the app already listens on public `tractor.status` + event `tractor.status.changed` (Phase 12 frozen list), **no app change required**.  
If any client was built against the Pipeline Reference’s `private-tractor.{id}`, that client would miss status events — treat as **doc error**, not a required production code change in this audit.

Also registered (not in frozen list; informational): private `tractor.{id}` channel exists in `routes/channels.php` but `TractorStatus` does **not** broadcast there.

### Differences (contract)

**No difference** between live production and the Phase 12 frozen WebSocket list (`private-gps_devices.{id}`, `report-received`, `tractor.status`, `tractor.status.changed`).

**Doc-only difference:** `docs/GPS Pipeline Reference.md` incorrectly documents `private-tractor.{id}` for `TractorStatus`.

---

## 3. API — Tractor Path Endpoint

### Expected

| Item | Contract |
|---|---|
| Route | `GET /api/tractors/{tractor}/path` |
| Auth / policy | Authenticated + `view` tractor |
| Query | `date` required (`shamsi_date`) |
| Behavior | Stream path points for that Jalali day |

### Live verification

| Check | Result |
|---|---|
| Route | ✅ `GET api/tractors/{tractor}/path` → `ActiveTractorController@getPath` |
| Validation | ✅ `date` required shamsi |
| Service | `TractorPathStreamService::getTractorPath` → streamed JSON with `latitude` / `longitude` fields |
| Read connection | `mysql_gps_read` (same host today) |

### Differences

**None** identified in route shape or required query parameter. Response remains streamed JSON path points (mobile already consumes this shape).

---

## 4. Summary Table

| Frozen contract | Live status | Difference for Android? |
|---|---|---|
| `POST /api/gps/reports` + `{success:true}` | Present | **No** |
| `private-gps_devices.{id}` / `report-received` | Present | **No** |
| `tractor.status` / `tractor.status.changed` | Present | **No** |
| `GET /api/tractors/{id}/path?date=` | Present | **No** |
| Pipeline doc `private-tractor.{id}` | Not used by `TractorStatus` | **Doc only** |

---

## 5. Related Non-Contract Observations (not Android breaks)

- 13 `tractor_gps` devices lack `tractor_id` → ingest discards those IMEIs (ops/data), not an API schema change.
- Today’s `gps_data` count was 0 at audit time; path for “today” may be empty until devices report — behavioral, not contract change.
- Reverb binds `0.0.0.0:8088`; client uses `ws.pistatapp.ir:443` — unchanged from Architecture Report.

---

*Phase 12 Android compatibility audit — read-only, 2026-08-05*
