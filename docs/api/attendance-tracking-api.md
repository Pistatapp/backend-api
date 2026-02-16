# Attendance Tracking API Documentation

This document describes the API endpoints for user-based attendance tracking. Attendance is enabled per user via the `attendance_tracking_enabled` flag (when creating/updating users) and the `enabled` field in the `attendance_trackings` table. Any user can have attendance tracking enabled regardless of their role.

## Base URL

```
https://your-domain.com/api
```

## Authentication

All endpoints require `auth:sanctum` authentication and `ensure.username` middleware (where applicable).

---

## Endpoints Overview

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/attendance/gps-report` | POST | Submit GPS data from mobile app |
| `/api/attendance/daily-reports` | GET | List daily attendance reports |
| `/api/attendance/daily-reports/{id}` | GET, PATCH | Show or update daily report |
| `/api/attendance/daily-reports/{id}/approve` | POST | Approve a daily report |
| `/api/attendance/payrolls/generate` | POST | Generate monthly payrolls |
| `/api/attendance/payrolls` | GET | List payrolls |
| `/api/attendance/payrolls/{id}` | GET | Show payroll |
| `/api/farms/{farm}/attendance/active-users` | GET | Get active users (GPS in last 10 min) |
| `/api/users/{user}/attendance/path` | GET | Get user GPS path for a date |
| `/api/users/{user}/attendance/status` | GET | Get user's current attendance status |
| `/api/farms/{farm}/shift-schedules` | GET | List shift schedules (calendar) |
| `/api/shift-schedules` | POST | Create shift schedules |
| `/api/shift-schedules/{id}` | GET, PATCH, DELETE | Show, update, or delete schedule |
| `/api/farms/{farm}/hr/active-users` | GET | Active users for HR map |

---

## GPS Report (Mobile App)

Submit GPS data from the mobile app for attendance tracking.

**Endpoint:** `POST /api/attendance/gps-report`

**Description:** Accepts GPS coordinates from authenticated users with attendance tracking enabled. The system validates the user has enabled attendance tracking and processes boundary detection for entry/exit events.

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `latitude` | float | Yes | GPS latitude |
| `longitude` | float | Yes | GPS longitude |
| `altitude` | float | No | Altitude in meters (default: 0) |
| `speed` | float | No | Speed |
| `bearing` | float | No | Bearing |
| `accuracy` | float | No | Accuracy in meters |
| `provider` | string | No | GPS provider: `gps`, `network`, `passive` (default: `gps`) |
| `time` | integer | Yes | Unix timestamp in milliseconds |

### Responses

| Status | Description |
|--------|-------------|
| `200 OK` | `{"success": true}` — GPS data stored and boundary detection processed |
| `401 Unauthorized` | Not authenticated |
| `403 Forbidden` | User does not have attendance tracking enabled |
| `400 Bad Request` | Invalid GPS data (missing latitude, longitude, or time) |

---

## Daily Reports

### List Daily Reports

**Endpoint:** `GET /api/attendance/daily-reports`

**Query Parameters:** `from_date`, `to_date`, `user_id`, `status`

### Show Daily Report

**Endpoint:** `GET /api/attendance/daily-reports/{attendanceDailyReport}`

### Update Daily Report

**Endpoint:** `PATCH /api/attendance/daily-reports/{attendanceDailyReport}`

### Approve Daily Report

**Endpoint:** `POST /api/attendance/daily-reports/{attendanceDailyReport}/approve`

---

## Payrolls

### Generate Payrolls

**Endpoint:** `POST /api/attendance/payrolls/generate`

Triggers background job to generate monthly payrolls for users with attendance tracking.

### List Payrolls

**Endpoint:** `GET /api/attendance/payrolls`

### Show Payroll

**Endpoint:** `GET /api/attendance/payrolls/{attendanceMonthlyPayroll}`

---

## Active Users (per Farm)

**Endpoint:** `GET /api/farms/{farm}/attendance/active-users`

Returns users with attendance tracking enabled who have submitted GPS data in the last 10 minutes. Results are cached for 1 minute.

**Authorization:** User must have `view` permission on the farm.

**Response:** Array of objects with `id`, `name`, `coordinate`, `last_update`, `is_in_zone`.

---

## User Attendance Path

**Endpoint:** `GET /api/users/{user}/attendance/path`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `date` | string | Date in `YYYY-MM-DD` format (default: today) |

Returns GPS path data for the user on the specified date. Each point includes `id`, `coordinate`, `speed`, `bearing`, `accuracy`, `provider`, `date_time` (ISO 8601).

---

## User Attendance Status

**Endpoint:** `GET /api/users/{user}/attendance/status`

Returns the user's current attendance status, including:
- Latest GPS position
- Active attendance session (if any) for today
- Session details (entry time, status)

---

## Shift Schedules

Shift schedules assign work shifts to users with shift-based attendance tracking. Users must have `attendance_tracking` with `work_type: shift_based` and `enabled: true`.

### List Shift Schedules (Calendar)

**Endpoint:** `GET /api/farms/{farm}/shift-schedules`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `month` | integer | Month (1–12, default: current) |
| `year` | integer | Year (default: current) |

Returns schedules grouped by date for users with attendance tracking on this farm.

**Authorization:** User must have `view` permission on the farm.

### Create Shift Schedules

**Endpoint:** `POST /api/shift-schedules`

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | integer | Yes | User ID (must have shift-based attendance tracking) |
| `shift_id` | integer | Yes | Work shift ID |
| `scheduled_dates` | array | Yes | Array of dates in **Shamsi format** `Y/m/d` (e.g. `1403/09/26`) |

**Validation Rules:**
- User must have attendance tracking enabled
- User must have `work_type: shift_based`
- User and shift must belong to the same farm
- No overlapping shifts on the same date

**Error Responses:**

| Status | Error |
|--------|-------|
| `400` | `User must have attendance tracking enabled` |
| `400` | `User must be shift-based to assign shifts` |
| `400` | `User and shift must belong to the same farm` |
| `400` | `Shift overlaps with existing schedule` |

### Show Shift Schedule

**Endpoint:** `GET /api/shift-schedules/{shift_schedule}`

### Update Shift Schedule

**Endpoint:** `PATCH /api/shift-schedules/{shift_schedule}`

**Request Body:** `shift_id`, `scheduled_date`, `status` (scheduled, completed, missed, cancelled)

### Delete Shift Schedule

**Endpoint:** `DELETE /api/shift-schedules/{shift_schedule}`

---

## Human Resources Map

**Endpoint:** `GET /api/farms/{farm}/hr/active-users`

Returns active users with attendance tracking for map display. Replaces the deprecated `/api/farms/{farm}/hr/active-labours` endpoint.

---

## Related: User Management

Attendance tracking is configured when creating or updating users:

- **Create User:** `POST /api/users` — Include `attendance_tracking_enabled`, `work_type`, `work_days`, `work_hours`, `start_work_time`, `end_work_time`, `hourly_wage`, `overtime_hourly_wage`, `tracking_device` when `attendance_tracking_enabled` is true
- **Update User:** `PUT /api/users/{user}` — Same fields for updating attendance tracking

See user API documentation for full request/response schemas.
