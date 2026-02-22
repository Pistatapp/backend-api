# Attendance System API Documentation

## Overview

The Attendance System API provides endpoints for managing **user** attendance (users with attendance tracking enabled), GPS tracking, shift scheduling, daily reports, and payroll. The system is **user-based**: attendance is tied to **users** and their `AttendanceTracking` settings per farm, not to a separate labour entity. GPS-based boundary detection automatically tracks presence within farm boundaries.

**Base URL:** `/api`

**Authentication:** All endpoints require authentication via Laravel Sanctum. Include the bearer token in the Authorization header:
```
Authorization: Bearer {token}
Accept: application/json
```

---

## Table of Contents

1. [GPS & Attendance Tracking](#gps--attendance-tracking)
2. [Active Users](#active-users)
3. [User Path & Status](#user-path--status)
4. [Work Shifts](#work-shifts)
5. [Shift Schedules](#shift-schedules)
6. [Daily Reports](#daily-reports)
7. [Payroll](#payroll)
8. [Dashboard Active Users Widget](#dashboard-active-users-widget)

---

## GPS & Attendance Tracking

### Submit GPS Report

Submit GPS location data from the authenticated user's mobile app. This endpoint automatically processes boundary detection and updates attendance sessions. The authenticated user must have **attendance tracking enabled** for a farm (via `AttendanceTracking` with `enabled: true`).

**Endpoint:** `POST /api/attendance/gps-report`

**Authentication:** Required (Bearer token). User must have an `AttendanceTracking` record with `enabled = true`.

**Request Body:**
```json
{
  "latitude": 34.052235,
  "longitude": -118.243683,
  "altitude": 92.4,
  "speed": 0.0,
  "bearing": 0.0,
  "accuracy": 5.1,
  "provider": "gps",
  "time": 1731632212000
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `latitude` | float | Yes | GPS latitude coordinate |
| `longitude` | float | Yes | GPS longitude coordinate |
| `altitude` | float | No | Altitude in meters (default: 0) |
| `speed` | float | No | Speed in m/s (default: 0) |
| `bearing` | float | No | Bearing/heading in degrees (default: 0) |
| `accuracy` | float | No | GPS accuracy in meters |
| `provider` | string | No | GPS provider type (default: "gps") |
| `time` | integer | Yes | Unix timestamp in milliseconds |

**Response:**

**Success (200 OK):**
```json
{
  "success": true
}
```

**Error Responses:**

- `401 Unauthorized` — User not authenticated
- `403 Forbidden` — Attendance tracking not enabled for the user. Response body: `{"error": "Attendance tracking not enabled"}`
- `400 Bad Request` — Invalid GPS data (missing `latitude`, `longitude`, or `time`). Response body: `{"error": "Invalid GPS data"}`
- `500 Internal Server Error` — Server error. Response body: `{"error": "Internal server error"}`

**Notes:**
- The endpoint creates or updates attendance sessions based on boundary detection
- GPS data is stored in `attendance_gps_data` for path tracking
- `UserAttendanceStatusChanged` and `AttendanceUpdated` events may be broadcast
- Boundary detection or broadcast failures are logged but do not fail the request

---

## Active Users

### Get Active Users for Farm

Retrieve list of **users** with attendance tracking enabled for the farm. Each user's current status is derived from their shift schedule and whether they are in the farm boundary (for today).

**Endpoint:** `GET /api/farms/{farm}/attendance/active-users`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `farm` | integer | Yes | Farm ID |

**Response:**

**Success (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "status": "present",
      "entrance_time": "08:15",
      "total_work_duration": "02:30"
    }
  ]
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | User ID |
| `name` | string | Display name (from profile or empty) |
| `status` | string \| null | `present` (in zone during shift), `absent` (outside zone during shift), `resting` (outside shift time), or `null` if no shift scheduled |
| `entrance_time` | string \| null | First GPS time inside shift window, in `H:i` format |
| `total_work_duration` | string \| null | Duration from entrance to now (or shift end), in `H:i` format |

**Notes:**
- Only users with `AttendanceTracking` for this farm and `enabled = true` are included
- Status is calculated from today's shift schedule and latest GPS position relative to farm boundary
- For map display or latest coordinates, use `GET /api/users/{user}/attendance/status` to get `latest_gps` per user

---

## User Path & Status

### Get User Attendance Path

Retrieve the GPS path for a user on a specific date.

**Endpoint:** `GET /api/users/{user}/attendance/path`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `user` | integer | Yes | User ID |

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `date` | string | No | today | Date in YYYY-MM-DD format |

**Response:**

**Success (200 OK):**
```json
{
  "data": [
    {
      "id": 123,
      "coordinate": {
        "lat": 34.052235,
        "lng": -118.243683,
        "altitude": 92.4
      },
      "speed": 0.0,
      "bearing": 0.0,
      "accuracy": 5.1,
      "provider": "gps",
      "date_time": "2024-01-15T10:30:00+00:00"
    }
  ]
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | GPS data point ID |
| `coordinate` | object | GPS coordinate (lat, lng, altitude) |
| `speed` | float | Speed in m/s |
| `bearing` | float | Bearing/heading in degrees |
| `accuracy` | float \| null | GPS accuracy in meters |
| `provider` | string | GPS provider type |
| `date_time` | string | ISO 8601 timestamp |

**Notes:**
- Returns empty array if no GPS data exists for the specified date
- Points are ordered chronologically

---

### Get User Current Attendance Status

Get the current attendance status of a user: latest GPS point and today's in-progress attendance session.

**Endpoint:** `GET /api/users/{user}/attendance/status`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `user` | integer | Yes | User ID |

**Response:**

**Success (200 OK):**
```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe"
    },
    "latest_gps": {
      "id": 123,
      "coordinate": {
        "lat": 34.052235,
        "lng": -118.243683,
        "altitude": 92.4
      },
      "speed": 0.0,
      "bearing": 0.0,
      "accuracy": 5.1,
      "provider": "gps",
      "date_time": "2024-01-15T10:30:00+00:00"
    },
    "attendance_session": {
      "id": 45,
      "date": "2024-01-15",
      "entry_time": "2024-01-15T08:00:00+00:00",
      "exit_time": null,
      "total_in_zone_duration": 120,
      "total_out_zone_duration": 30,
      "status": "in_progress"
    }
  }
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `user` | object | User id and name (from profile or mobile) |
| `latest_gps` | object \| null | Latest GPS data point (same structure as path); null if none |
| `attendance_session` | object \| null | Today's in-progress attendance session (if exists) |

**Attendance Session Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Session ID |
| `date` | string | Date in YYYY-MM-DD format |
| `entry_time` | string \| null | ISO 8601 timestamp of entry |
| `exit_time` | string \| null | ISO 8601 timestamp of exit |
| `total_in_zone_duration` | integer | Total minutes spent in zone |
| `total_out_zone_duration` | integer | Total minutes spent outside zone |
| `status` | string | `in_progress` or `completed` |

---

## Work Shifts

### List Work Shifts

Get all work shifts for a farm.

**Endpoint:** `GET /api/farms/{farm}/work-shifts`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `farm` | integer | Yes | Farm ID |

**Response:**

**Success (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "farm_id": 1,
      "name": "Morning Shift",
      "start_time": "08:00",
      "end_time": "16:00",
      "work_hours": 8.00,
      "created_at": "2024-01-01T00:00:00Z",
      "updated_at": "2024-01-01T00:00:00Z"
    }
  ]
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Shift ID |
| `farm_id` | integer | Farm ID |
| `name` | string | Shift name |
| `start_time` | string | Start time in HH:mm format |
| `end_time` | string | End time in HH:mm format |
| `work_hours` | decimal | Number of work hours |
| `created_at` | string | ISO 8601 timestamp |
| `updated_at` | string | ISO 8601 timestamp |

---

### Create Work Shift

Create a new work shift for a farm.

**Endpoint:** `POST /api/farms/{farm}/work-shifts`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `farm` | integer | Yes | Farm ID |

**Request Body:**
```json
{
  "name": "Morning Shift",
  "start_time": "08:00",
  "end_time": "16:00",
  "work_hours": 8.00
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | Yes | Shift name (max 255 characters) |
| `start_time` | string | Yes | Start time in HH:mm format |
| `end_time` | string | Yes | End time in HH:mm format |
| `work_hours` | decimal | Yes | Number of work hours (0-24) |

**Response:**

**Success (201 Created):**
```json
{
  "data": {
    "id": 1,
    "farm_id": 1,
    "name": "Morning Shift",
    "start_time": "08:00",
    "end_time": "16:00",
    "work_hours": 8.00,
    "created_at": "2024-01-01T00:00:00Z",
    "updated_at": "2024-01-01T00:00:00Z"
  }
}
```

**Notes:**
- If `work_hours` is not provided, it will be calculated from start_time and end_time
- Supports shifts that span midnight (e.g., 22:00 to 06:00)

---

### Get Work Shift

Get a specific work shift.

**Endpoint:** `GET /api/work-shifts/{workShift}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `workShift` | integer | Yes | Work Shift ID |

**Response:**

**Success (200 OK):**
```json
{
  "data": {
    "id": 1,
    "farm_id": 1,
    "name": "Morning Shift",
    "start_time": "08:00",
    "end_time": "16:00",
    "work_hours": 8.00,
    "created_at": "2024-01-01T00:00:00Z",
    "updated_at": "2024-01-01T00:00:00Z"
  }
}
```

---

### Update Work Shift

Update an existing work shift.

**Endpoint:** `PUT /api/work-shifts/{workShift}` or `PATCH /api/work-shifts/{workShift}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `workShift` | integer | Yes | Work Shift ID |

**Request Body:**
```json
{
  "name": "Updated Morning Shift",
  "start_time": "09:00",
  "end_time": "17:00",
  "work_hours": 8.00
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | No | Shift name (max 255 characters) |
| `start_time` | string | No | Start time in HH:mm format |
| `end_time` | string | No | End time in HH:mm format |
| `work_hours` | decimal | No | Number of work hours (0-24) |

**Response:**

**Success (200 OK):**
```json
{
  "data": {
    "id": 1,
    "farm_id": 1,
    "name": "Updated Morning Shift",
    "start_time": "09:00",
    "end_time": "17:00",
    "work_hours": 8.00,
    "created_at": "2024-01-01T00:00:00Z",
    "updated_at": "2024-01-15T10:30:00Z"
  }
}
```

---

### Delete Work Shift

Delete a work shift.

**Endpoint:** `DELETE /api/work-shifts/{workShift}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `workShift` | integer | Yes | Work Shift ID |

**Response:**

**Success (204 No Content):**

**Error Responses:**

- `400 Bad Request` - Cannot delete shift with scheduled users (existing shift schedules)

---

## Shift Schedules

### List Shift Schedules (Calendar View)

Get shift schedules for a farm in calendar format, grouped by date. Only includes **users** who have attendance tracking enabled for this farm.

**Endpoint:** `GET /api/farms/{farm}/shift-schedules`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `farm` | integer | Yes | Farm ID |

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `month` | integer | No | current month | Month (1-12) |
| `year` | integer | No | current year | Year (4 digits) |

**Response:**

**Success (200 OK):**
```json
{
  "data": [
    {
      "date": "2024-01-15",
      "schedules": [
        {
          "id": 1,
          "user": {
            "id": 1,
            "name": "John Doe",
            "mobile": "09123456789",
            "username": "john",
            "is_active": true,
            "last_activity_at": "1402/11/25 14:30:00",
            "role": "worker",
            "attendance_tracking": { ... },
            "can": { ... }
          },
          "shift": {
            "id": 1,
            "name": "Morning Shift",
            "start_time": "08:00",
            "end_time": "16:00",
            "work_hours": 8.00
          },
          "scheduled_date": "2024-01-15",
          "status": "scheduled",
          "created_at": "2024-01-01T00:00:00Z",
          "updated_at": "2024-01-01T00:00:00Z"
        }
      ]
    }
  ]
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `date` | string | Date in YYYY-MM-DD format |
| `schedules` | array | Array of shift schedules for that date |

**Schedule Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Schedule ID |
| `user` | object | User resource (id, name, mobile, etc.) |
| `shift` | object | Work shift resource |
| `scheduled_date` | string | Scheduled date (Jalali Y/m/d in response) |
| `status` | string | Status: `scheduled`, `completed`, `missed`, `cancelled` |
| `created_at` | string | Jalali date (Y/m/d) |

---

### Create Shift Schedule(s)

Assign a work shift to a **user** for one or more dates. Can create multiple schedules in one request.

**Endpoint:** `POST /api/shift-schedules`

**Authentication:** Required

**Request Body:**
```json
{
  "user_id": 1,
  "shift_id": 1,
  "scheduled_dates": ["1402/11/01", "1402/11/02"]
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `user_id` | integer | Yes | User ID (must exist) |
| `shift_id` | integer | Yes | Work Shift ID (must exist) |
| `scheduled_dates` | array | Yes | Array of **Shamsi (Jalali)** date strings |

**Response:**

**Success (200 OK):** Returns a **collection** of created schedule resources (one per date in `scheduled_dates`).

**Error Responses:**

- `400 Bad Request` — `{"error": "User must have attendance tracking enabled"}`
- `400 Bad Request` — `{"error": "User must be shift-based to assign shifts"}`
- `400 Bad Request` — `{"error": "User and shift must belong to the same farm"}`
- `400 Bad Request` — `{"error": "Shift overlaps with existing schedule"}`

**Notes:**
- User must have `AttendanceTracking` with `enabled = true` and `work_type = shift_based`
- User's attendance farm and shift's farm must match
- Overlapping shifts on the same date for the same user are not allowed
- `scheduled_dates` use **Shamsi (Jalali)** date format

---

### Get Shift Schedule

Get a specific shift schedule.

**Endpoint:** `GET /api/shift-schedules/{shift_schedule}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `shift_schedule` | integer | Yes | Shift Schedule ID |

**Response:**

**Success (200 OK):**
```json
{
  "data": {
    "id": 1,
    "user": { "id": 1, "name": "John Doe", ... },
    "shift": { "id": 1, "name": "Morning Shift", "start_time": "08:00", "end_time": "16:00", "work_hours": 8.00, ... },
    "scheduled_date": "1402/11/25",
    "status": "scheduled",
    "created_at": "1402/10/01"
  }
}
```

---

### Update Shift Schedule

Update a shift schedule.

**Endpoint:** `PUT /api/shift-schedules/{shift_schedule}` or `PATCH /api/shift-schedules/{shift_schedule}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `shift_schedule` | integer | Yes | Shift Schedule ID |

**Request Body:**
```json
{
  "shift_id": 2,
  "scheduled_date": "2024-01-16",
  "status": "completed"
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `shift_id` | integer | No | Work Shift ID (must exist; must belong to same farm as user) |
| `scheduled_date` | string | No | Date (Y-m-d) |
| `status` | string | No | Status: `scheduled`, `completed`, `missed`, `cancelled` |

**Response:**

**Success (200 OK):**
```json
{
  "data": {
    "id": 1,
    "user": { "id": 1, "name": "John Doe", ... },
    "shift": { "id": 2, "name": "Evening Shift", "start_time": "16:00", "end_time": "00:00", "work_hours": 8.00, ... },
    "scheduled_date": "1402/11/26",
    "status": "completed",
    "created_at": "1402/10/01"
  }
}
```

---

### Delete Shift Schedule

Delete a shift schedule.

**Endpoint:** `DELETE /api/shift-schedules/{shift_schedule}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `shift_schedule` | integer | Yes | Shift Schedule ID |

**Response:**

**Success (204 No Content):**

---

## Daily Reports

### List Daily Reports

Get a list of **user** daily reports (inbox view). Default filter is `status=pending`.

**Endpoint:** `GET /api/attendance/daily-reports`

**Authentication:** Required

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from_date` | string | No | Filter from date (YYYY-MM-DD) |
| `to_date` | string | No | Filter to date (YYYY-MM-DD) |
| `user_id` | integer | No | Filter by user ID |
| `status` | string | No | Filter by status: `pending`, `approved`, `rejected` (default list is `pending` only) |

**Response:**

**Success (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "user": {
        "id": 1,
        "name": "John Doe",
        "mobile": "09123456789",
        "username": "john",
        "is_active": true,
        "last_activity_at": "1402/11/25 14:30:00",
        "role": "worker",
        "attendance_tracking": { ... },
        "can": { ... }
      },
      "date": "2024-01-15",
      "scheduled_hours": 8.00,
      "actual_work_hours": 7.50,
      "overtime_hours": 0.00,
      "time_outside_zone": 30,
      "productivity_score": 93.75,
      "status": "pending",
      "admin_added_hours": null,
      "admin_reduced_hours": null,
      "notes": null,
      "approver": null,
      "approved_at": null,
      "created_at": "2024-01-15T08:00:00Z",
      "updated_at": "2024-01-15T08:00:00Z"
    }
  ],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "...",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Report ID |
| `user` | object | User resource |
| `date` | string | Report date in YYYY-MM-DD format |
| `scheduled_hours` | decimal | Scheduled work hours |
| `actual_work_hours` | decimal | Actual work hours worked |
| `overtime_hours` | decimal | Overtime hours |
| `time_outside_zone` | integer | Minutes spent outside zone |
| `productivity_score` | decimal | Productivity score percentage |
| `status` | string | Status: `pending`, `approved`, `rejected` |
| `admin_added_hours` | decimal\|null | Hours added by admin |
| `admin_reduced_hours` | decimal\|null | Hours reduced by admin |
| `notes` | string\|null | Admin notes (max 300 characters) |
| `approver` | object\|null | User who approved the report |
| `approved_at` | string\|null | ISO 8601 timestamp of approval |
| `created_at` | string | ISO 8601 timestamp |
| `updated_at` | string | ISO 8601 timestamp |

**Notes:**
- Default filter shows only `pending` reports
- Results are paginated
- Ordered by date descending

---

### Get Daily Report

Get a specific daily report.

**Endpoint:** `GET /api/attendance/daily-reports/{attendanceDailyReport}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `attendanceDailyReport` | integer | Yes | Attendance Daily Report ID |

**Response:**

**Success (200 OK):**
```json
{
  "data": {
    "id": 1,
    "user": { "id": 1, "name": "John Doe", ... },
    "date": "2024-01-15",
    "scheduled_hours": 8.00,
    "actual_work_hours": 7.50,
    "overtime_hours": 0.00,
    "time_outside_zone": 30,
    "productivity_score": 93.75,
    "status": "pending",
    "admin_added_hours": null,
    "admin_reduced_hours": null,
    "notes": null,
    "approver": null,
    "approved_at": null,
    "created_at": "2024-01-15T08:00:00Z",
    "updated_at": "2024-01-15T08:00:00Z"
  }
}
```

---

### Update Daily Report

Update a daily report (admin edits).

**Endpoint:** `PATCH /api/attendance/daily-reports/{attendanceDailyReport}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `attendanceDailyReport` | integer | Yes | Attendance Daily Report ID |

**Request Body:**
```json
{
  "admin_added_hours": 0.5,
  "admin_reduced_hours": 0.0,
  "status": "approved",
  "notes": "Good performance"
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `admin_added_hours` | decimal | No | Hours to add (min: 0) |
| `admin_reduced_hours` | decimal | No | Hours to reduce (min: 0) |
| `status` | string | No | Status: `pending`, `approved`, `rejected` |
| `notes` | string | No | Admin notes (max 300 characters) |

**Response:**

**Success (200 OK):** Same structure as Get Daily Report (with updated fields and loaded `user`, `approver`).

---

### Approve Daily Report

Approve a daily report. Sets status to `approved`, records the authenticated user as approver, and recalculates `actual_work_hours` using `admin_added_hours` and `admin_reduced_hours`.

**Endpoint:** `POST /api/attendance/daily-reports/{attendanceDailyReport}/approve`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `attendanceDailyReport` | integer | Yes | Attendance Daily Report ID |

**Response:**

**Success (200 OK):**
```json
{
  "message": "Report approved successfully",
  "report": {
    "id": 1,
    "user": { "id": 1, "name": "John Doe", ... },
    "date": "2024-01-15",
    "scheduled_hours": 8.00,
    "actual_work_hours": 7.50,
    "overtime_hours": 0.00,
    "time_outside_zone": 30,
    "productivity_score": 93.75,
    "status": "approved",
    "admin_added_hours": null,
    "admin_reduced_hours": null,
    "notes": null,
    "approver": { "id": 2, "name": "Admin User", ... },
    "approved_at": "2024-01-15T10:30:00Z",
    "created_at": "2024-01-15T08:00:00Z",
    "updated_at": "2024-01-15T10:30:00Z"
  }
}
```

**Notes:**
- Sets status to `approved` and records authenticated user as approver
- Recalculates `actual_work_hours` from base + admin_added_hours - admin_reduced_hours

---

## Payroll

### Generate Payroll

Generate payroll reports for a date range. This queues a background job to process payroll calculations.

**Endpoint:** `POST /api/attendance/payrolls/generate`

**Authentication:** Required

**Request Body:**
```json
{
  "from_date": "2024-01-01",
  "to_date": "2024-01-31",
  "user_id": 1
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from_date` | string | Yes | Start date in YYYY-MM-DD format |
| `to_date` | string | Yes | End date in YYYY-MM-DD format (must be >= from_date) |
| `user_id` | integer | No | Specific user ID (optional; generates for all eligible users if omitted) |

**Response:**

**Success (202 Accepted):**
```json
{
  "message": "Payroll generation job queued successfully"
}
```

**Error Responses:**

- `422 Unprocessable Entity` — Validation errors (invalid dates, date range)

**Notes:**
- Payroll generation runs asynchronously (`GenerateMonthlyPayrollJob`)
- Monthly payrolls are created for each user in the date range (or the specified user)
- Calculations include base wages, overtime, additions, and deductions

---

### List Payroll Reports

Get a list of monthly payroll reports.

**Endpoint:** `GET /api/attendance/payrolls`

**Authentication:** Required

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from_date` | string | No | Filter from creation date (YYYY-MM-DD) |
| `to_date` | string | No | Filter to creation date (YYYY-MM-DD) |
| `user_id` | integer | No | Filter by user ID |
| `month` | integer | No | Filter by month (1-12) |
| `year` | integer | No | Filter by year (4 digits) |

**Response:**

**Success (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "user": { "id": 1, "name": "John Doe", ... },
      "month": 1,
      "year": 2024,
      "total_work_hours": 160.00,
      "total_required_hours": 160.00,
      "total_overtime_hours": 0.00,
      "base_wage_total": 8000.00,
      "overtime_wage_total": 0.00,
      "additions": 0.00,
      "deductions": 0.00,
      "final_total": 8000.00,
      "generated_at": "2024-02-01T00:00:00Z",
      "created_at": "2024-02-01T00:00:00Z",
      "updated_at": "2024-02-01T00:00:00Z"
    }
  ],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "...",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Payroll ID |
| `user` | object | User resource |
| `month` | integer | Month (1-12) |
| `year` | integer | Year (4 digits) |
| `total_work_hours` | decimal | Total hours worked |
| `total_required_hours` | decimal | Total required hours |
| `total_overtime_hours` | decimal | Total overtime hours |
| `base_wage_total` | decimal | Base wage total |
| `overtime_wage_total` | decimal | Overtime wage total |
| `additions` | decimal | Additional payments |
| `deductions` | decimal | Deductions |
| `final_total` | decimal | Final payroll total |
| `generated_at` | string\|null | ISO 8601 timestamp of generation |
| `created_at` | string | ISO 8601 timestamp |
| `updated_at` | string | ISO 8601 timestamp |

**Notes:**
- Results are paginated
- Ordered by year and month descending

---

### Get Payroll Report

Get a specific monthly payroll report.

**Endpoint:** `GET /api/attendance/payrolls/{attendanceMonthlyPayroll}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `attendanceMonthlyPayroll` | integer | Yes | Attendance Monthly Payroll ID |

**Response:**

**Success (200 OK):**
```json
{
  "data": {
    "id": 1,
    "user": { "id": 1, "name": "John Doe", ... },
    "month": 1,
    "year": 2024,
    "total_work_hours": 160.00,
    "total_required_hours": 160.00,
    "total_overtime_hours": 0.00,
    "base_wage_total": 8000.00,
    "overtime_wage_total": 0.00,
    "additions": 0.00,
    "deductions": 0.00,
    "final_total": 8000.00,
    "generated_at": "2024-02-01T00:00:00Z",
    "created_at": "2024-02-01T00:00:00Z",
    "updated_at": "2024-02-01T00:00:00Z"
  }
}
```

---

## Dashboard Active Users Widget

### Get Active Users for Dashboard Widget

Returns a compact list of active users (with attendance tracking for the farm) for dashboard widgets: id, name, entry time, and working hours. Uses the same underlying service as [Get Active Users for Farm](#get-active-users-for-farm) but with a different response shape (no `status` / `entrance_time` / `total_work_duration`; instead `entry_time` and `working_hours`).

**Endpoint:** `GET /api/farms/{farm}/dashboard/active-labours`

**Authentication:** Required (must be allowed to view the farm)

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `farm` | integer | Yes | Farm ID |

**Response:**

**Success (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "entry_time": "2024-01-15T08:15:00+00:00",
      "working_hours": 2
    }
  ]
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | User ID |
| `name` | string | Display name |
| `entry_time` | string \| null | ISO 8601 timestamp of session entry (if in-progress session exists) |
| `working_hours` | number | Hours from entry to now (integer; 0 if no entry) |

**Notes:**
- For map display with coordinates, use `GET /api/users/{user}/attendance/status` per user to obtain `latest_gps`
- The deprecated endpoint `GET /api/farms/{farm}/hr/active-labours` is no longer available; use this or the attendance active-users endpoint instead

---

## Error Handling

### Standard Error Response Format

All error responses follow this format:

```json
{
  "error": "Error message",
  "message": "Detailed error message (optional)"
}
```

### HTTP Status Codes

| Code | Description |
|------|-------------|
| `200` | Success |
| `201` | Created |
| `202` | Accepted (async operation) |
| `204` | No Content (successful deletion) |
| `400` | Bad Request (validation errors, invalid data) |
| `401` | Unauthorized (authentication required) |
| `404` | Not Found (resource doesn't exist) |
| `422` | Unprocessable Entity (validation errors) |
| `500` | Internal Server Error |

### Common Error Scenarios

1. **Authentication Required**
   - Status: `401 Unauthorized`
   - Solution: Include valid bearer token in Authorization header

2. **Resource Not Found**
   - Status: `404 Not Found`
   - Solution: Verify resource ID exists

3. **Validation Errors**
   - Status: `422 Unprocessable Entity` or `400 Bad Request`
   - Solution: Check request body matches required format

4. **Overlapping Shifts**
   - Status: `400 Bad Request`
   - Solution: Ensure shifts don't overlap on the same date for the same user

5. **Invalid User / Shift**
   - Status: `400 Bad Request`
   - Solution: User must have attendance tracking enabled and be shift-based to assign shifts; user and shift must belong to the same farm

---

## Data Models

Attendance is **user-based**. Users have an optional `AttendanceTracking` record per farm. Sessions, reports, schedules, and payrolls reference `user_id`.

### Attendance Tracking

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Record ID |
| `user_id` | integer | User ID |
| `farm_id` | integer | Farm ID |
| `work_type` | string | `shift_based` or `hourly_based` |
| `work_days` | array | Array of work days |
| `work_hours` | decimal | Work hours |
| `start_work_time` | time | Start work time |
| `end_work_time` | time | End work time |
| `hourly_wage` | decimal | Hourly wage |
| `overtime_hourly_wage` | decimal | Overtime hourly wage |
| `enabled` | boolean | Whether tracking is enabled |

### Attendance Session

Table: `attendance_sessions`

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Session ID |
| `user_id` | integer | User ID |
| `date` | date | Session date |
| `entry_time` | datetime | Entry time |
| `exit_time` | datetime | Exit time |
| `total_in_zone_duration` | integer | Total minutes in zone |
| `total_out_zone_duration` | integer | Total minutes outside zone |
| `status` | string | `in_progress` or `completed` |

### Work Shift

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Shift ID |
| `farm_id` | integer | Farm ID |
| `name` | string | Shift name |
| `start_time` | time | Start time (HH:mm) |
| `end_time` | time | End time (HH:mm) |
| `work_hours` | decimal | Work hours |

### Attendance Shift Schedule

Table: `attendance_shift_schedules`

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Schedule ID |
| `user_id` | integer | User ID |
| `shift_id` | integer | Work Shift ID |
| `scheduled_date` | date | Scheduled date |
| `status` | string | `scheduled`, `completed`, `missed`, `cancelled` |

### Attendance Daily Report

Table: `attendance_daily_reports`

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Report ID |
| `user_id` | integer | User ID |
| `date` | date | Report date |
| `scheduled_hours` | decimal | Scheduled work hours |
| `actual_work_hours` | decimal | Actual work hours |
| `overtime_hours` | decimal | Overtime hours |
| `time_outside_zone` | integer | Minutes outside zone |
| `productivity_score` | decimal | Productivity score |
| `status` | string | `pending`, `approved`, `rejected` |
| `admin_added_hours` | decimal | Admin added hours |
| `admin_reduced_hours` | decimal | Admin reduced hours |
| `notes` | string | Admin notes (max 300) |
| `approved_by` | integer | Approver user ID |
| `approved_at` | datetime | Approval timestamp |

### Attendance Monthly Payroll

Table: `attendance_monthly_payrolls`

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Payroll ID |
| `user_id` | integer | User ID |
| `month` | integer | Month (1-12) |
| `year` | integer | Year |
| `total_work_hours` | decimal | Total work hours |
| `total_required_hours` | decimal | Total required hours |
| `total_overtime_hours` | decimal | Total overtime hours |
| `base_wage_total` | decimal | Base wage total |
| `overtime_wage_total` | decimal | Overtime wage total |
| `additions` | decimal | Additions |
| `deductions` | decimal | Deductions |
| `final_total` | decimal | Final total |
| `generated_at` | datetime | Generation timestamp |

---

## Best Practices

### GPS Reporting

1. **Frequency**: Report GPS data every 1-5 minutes for accurate tracking
2. **Accuracy**: Ensure GPS accuracy is reasonable (preferably < 10 meters)
3. **Error Handling**: Implement retry logic for failed GPS submissions
4. **Battery Optimization**: Balance reporting frequency with battery life

### Shift Scheduling

1. **Overlap Prevention**: Always check for overlapping shifts before creating schedules
2. **User Type**: User must have attendance tracking enabled and `work_type = shift_based` to assign shifts
3. **Status Management**: Update shift status appropriately (`completed`, `missed`, `cancelled`)

### Daily Reports

1. **Approval Workflow**: Review reports before approval
2. **Admin Adjustments**: Use `admin_added_hours` and `admin_reduced_hours` for corrections
3. **Notes**: Add notes when making adjustments for audit trail

### Payroll Generation

1. **Date Ranges**: Generate payrolls monthly for accurate calculations
2. **Async Processing**: Payroll generation is async; check status via list endpoint
3. **Verification**: Review generated payrolls before finalizing

---

## Rate Limiting

API endpoints may be subject to rate limiting. Check response headers for rate limit information:

- `X-RateLimit-Limit`: Maximum requests allowed
- `X-RateLimit-Remaining`: Remaining requests in current window
- `X-RateLimit-Reset`: Timestamp when rate limit resets

---

## Webhooks & Events

The system may broadcast the following events (Laravel broadcasting):

- **`UserAttendanceStatusChanged`**: When the user submits a GPS report (payload includes user and GPS data)
- **`AttendanceUpdated`**: When an attendance session is created or updated by boundary detection (payload includes user and session)

Subscribe via Laravel Echo / broadcasting channels as configured in the application.

---

## Support & Contact

For API support, please contact the development team or refer to the main project documentation.

---

**Document Version:** 2.0  
**Last Updated:** 2025-02-21  
**API Version:** v1  

**Changelog (v2.0):** Attendance system is now **user-based**. All endpoints use `/api/attendance/` or user/farm paths as listed. Labour-specific paths and `labour_id` have been replaced with **users** and `user_id`. Shift schedule create uses `user_id` and `scheduled_dates` (array of Shamsi dates). Response shapes use `user` instead of `labour`. Added Dashboard Active Users Widget; deprecated HR Map endpoint removed.

