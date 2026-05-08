# API Changes for Frontend & Mobile (May 2026)

This document describes API behavior and route changes that were added/updated for:
- Irrigation
- Tractor tasks and tractor maintenance flows
- Warning settings for tractor periodic service
- Nutrient Diagnosis (C.N.D)
- Cold requirement temperature inputs
- Load estimation (yield prediction input override)

All routes below assume existing auth middleware (`auth:sanctum`) and existing farm access rules.

---

## 1) Irrigation API Behavior Changes

### 1.1 Edit/Delete permission window changed
- Irrigation edit/delete availability is now:
  - **Creator**: up to **72 hours** after creation
  - **Farm admin**: up to **72 hours** after irrigation `end_time`

Frontend impact:
- `can.update` / `can.delete` on irrigation resources may now stay `true` longer than before.

### 1.2 Irrigation reports are admin-approved only
- Irrigation report filtering now returns only irrigations verified by admin.

Route (existing):
- `POST /api/farms/{farm}/irrigations/filter-reports`

Frontend impact:
- Unverified finished irrigations are no longer included in filtered report results.

---

## 2) Cold Requirement API (Temperature Decimal Support)

Route (existing):
- `POST /api/farms/{farm}/cold_requirement`

### Request change
- `min_temp` and `max_temp` now accept **decimal** values.

Example valid values:
- `7.5`
- `0.25`
- `-1.5`

Frontend impact:
- UI can send fractional temperature values without integer-only restriction.

---

## 3) Tractor Task API Behavior Changes

### 3.1 Edit/Delete permission window logic changed
- Tractor task edit/delete window is now calculated from **task completion time** (`date + end_time`), not from `created_at`.
- Windows:
  - **Creator**: up to **24 hours** after task completion
  - **Farm admin**: up to **48 hours** after task completion

Frontend impact:
- `can.update` / `can.delete` should be interpreted relative to task end time.

---

## 4) New Tractor Repair Shop Routes

### 4.1 Enter repair shop
- **POST** `/api/tractors/{tractor}/repair-shop/enter`

#### Request body
- `maintained_by` (optional, integer labour id)
- `description` (optional, string)

If `maintained_by` is not sent, backend uses a farm labour automatically when available.

#### Success response
- `message`
- `data.tractor` (updated tractor resource)
- `data.maintenance_report_id`

#### Common validation/state response
- `422` if tractor is already in repair shop
- `422` if no labour is available for report creation

### 4.2 Exit repair shop
- **POST** `/api/tractors/{tractor}/repair-shop/exit`

#### Success response
- `message`
- `data` (updated tractor resource)

#### Common validation/state response
- `422` if tractor is not currently in repair shop

### 4.3 Tractor service interval reset
- **POST** `/api/tractors/{tractor}/service-reset`

Resets service tracking baseline for that tractor.

#### Success response
- `message`
- `data` (updated tractor resource)

---

## 5) Tractor Resource/State Additions

Tractor now includes service/repair state fields used by frontend workflows:
- `is_in_repair_shop` (boolean)
- `last_service_at` (datetime, nullable)
- `last_service_notified_at` (datetime, nullable)

Frontend usage:
- Use `is_in_repair_shop` to disable assignment/active workflows and show repair state.

---

## 6) Maintenance Report Response Addition

Route family (existing):
- `/api/maintenance_reports/*`

### Resource change
`MaintenanceReportResource` now includes:
- `repair_duration_hours` (float, nullable)

`repair_duration_hours` is present when both:
- `repair_shop_entered_at`
- `repair_shop_exited_at`
exist.

---

## 7) Warning Settings: New Tractor Periodic Service Key

Route (existing):
- `GET /api/v1/warnings`
- `POST /api/v1/warnings`

### New warning key
- `tractor_periodic_service`

### Configuration payload (POST `/api/v1/warnings`)
```json
{
  "key": "tractor_periodic_service",
  "enabled": true,
  "parameters": {
    "interval_hours": 250,
    "interval_km": 5000
  }
}
```

### Parameter types
- `interval_hours`: numeric, `> 0`
- `interval_km`: numeric, `> 0`

Frontend impact:
- Warning settings screen can now configure periodic tractor service alert thresholds.

---

## 8) Nutrient Diagnosis (C.N.D) API Changes

Base route group (existing):
- `/api/farms/{farm}/nutrient_diagnosis`

### 8.1 New routes
- **PUT** `/api/farms/{farm}/nutrient_diagnosis/{request}` (update request samples)
- **POST** `/api/farms/{farm}/nutrient_diagnosis/{request}/approve`
- **POST** `/api/farms/{farm}/nutrient_diagnosis/{request}/reject`

### 8.2 Existing route behavior changes
- **POST** `/api/farms/{farm}/nutrient_diagnosis`
  - `samples.*.field_area` is now optional; backend auto-fills it from field geometry when omitted.
- **POST** `/api/farms/{farm}/nutrient_diagnosis/{request}/response`
  - Response upload is now allowed only when request status is `approved`.

### 8.3 Status workflow
- `pending`: editable/deletable by request owner
- `approved`: not editable/not deletable
- `rejected`: editable/deletable again

### 8.4 Resource changes
`NutrientDiagnosisRequestResource` now includes:
- `approved_at` (nullable datetime string)
- `can.update` (boolean) in `can` object

Frontend impact:
- Render action buttons based on `can.update` and `can.delete`.
- Use `approved_at` + `status` for status UI.

---

## 9) Load Estimation API Change (Cluster Weight Override)

Route (existing):
- `POST /api/farms/{farm}/load_estimation`

### Request change
New optional field:
- `cluster_weight` (numeric, `>= 0`)

When sent, this value overrides the table's `fruit_cluster_weight` during estimation calculations.

Frontend impact:
- UI can allow user-entered cluster weight and send it directly for recalculation.

---

## 10) Quick Route Summary (New Endpoints)

- `POST /api/tractors/{tractor}/repair-shop/enter`
- `POST /api/tractors/{tractor}/repair-shop/exit`
- `POST /api/tractors/{tractor}/service-reset`
- `PUT /api/farms/{farm}/nutrient_diagnosis/{request}`
- `POST /api/farms/{farm}/nutrient_diagnosis/{request}/approve`
- `POST /api/farms/{farm}/nutrient_diagnosis/{request}/reject`

---

## 11) Application Version Releases (Mobile/Desktop Update Flow)

This feature enables root users to publish new application versions and allows authenticated users to fetch/download the latest release.

### 11.1 Root uploads a new release
- **POST** `/api/app-releases`
- Access: `root` role only

#### Request body (`multipart/form-data`)
- `version` (required, string, unique, format like `v12.0.11`)
- `file` (required, binary package file, max 500MB)
- `release_notes` (optional, text)

#### Success response
- `201 Created`
- Returns release metadata including:
  - `id`
  - `version`
  - `release_notes`
  - `published_at`
  - `download_url`
  - `file` metadata (`name`, `size`, `mime_type`)

### 11.2 User checks latest available version
- **GET** `/api/app-releases/latest`
- Access: any authenticated active user

#### Success response
- `200 OK`
- Returns latest published release metadata in the same shape as upload response.

### 11.3 User downloads selected release package
- **GET** `/api/app-releases/{appRelease}/download`
- Access: any authenticated active user

#### Success response
- `200 OK`
- Streams the release package as downloadable file.

