# Worker Attendance System API Documentation

## Overview

The Worker Attendance System API provides comprehensive endpoints for managing worker attendance, GPS tracking, shift scheduling, daily reports, and payroll. The system uses GPS-based boundary detection to automatically track worker presence within farm boundaries.

**Base URL:** `/api`

**Authentication:** All endpoints require authentication via Laravel Sanctum. Include the bearer token in the Authorization header:
```
Authorization: Bearer {token}
```

---

## Table of Contents

1. [GPS & Attendance Tracking](#gps--attendance-tracking)
2. [Active Workers](#active-workers)
3. [Worker Path & Status](#worker-path--status)
4. [Work Shifts](#work-shifts)
5. [Worker Shift Schedules](#worker-shift-schedules)
6. [Worker Daily Reports](#worker-daily-reports)
7. [Worker Payroll](#worker-payroll)
8. [Human Resources Map](#human-resources-map)

---

## GPS & Attendance Tracking

### Submit GPS Report

Submit GPS location data from worker mobile app. This endpoint automatically processes boundary detection and updates attendance sessions.

**Endpoint:** `POST /workers/gps-report`

**Authentication:** Required (Worker)

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

- `401 Unauthorized` - User not authenticated
- `404 Not Found` - Employee not found for authenticated user
- `400 Bad Request` - Invalid GPS data (missing required fields)
- `500 Internal Server Error` - Server error

**Notes:**
- The endpoint automatically creates or updates attendance sessions based on boundary detection
- GPS data is saved to the database for path tracking
- Worker status changes are broadcast via events
- Boundary detection errors are logged but don't fail the request

---

## Active Workers

### Get Active Workers for Farm

Retrieve list of active workers (workers who sent GPS data in the last 10 minutes) for a specific farm.

**Endpoint:** `GET /farms/{farm}/workers/active`

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
      "fname": "John",
      "lname": "Doe",
      "coordinate": {
        "lat": 34.052235,
        "lng": -118.243683,
        "altitude": 92.4
      },
      "last_update": "2024-01-15T10:30:00Z",
      "is_in_zone": true
    }
  ]
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Employee ID |
| `name` | string | Full name of employee |
| `fname` | string | First name |
| `lname` | string | Last name |
| `coordinate` | object | Latest GPS coordinate with lat, lng, altitude |
| `last_update` | string | ISO 8601 timestamp of last GPS update |
| `is_in_zone` | boolean | Whether worker is currently within farm boundary |

**Notes:**
- Results are cached for 1 minute
- Only workers with GPS data in the last 10 minutes are included
- `is_in_zone` is calculated based on farm boundary polygon

---

## Worker Path & Status

### Get Worker Path

Retrieve the complete GPS path for a worker on a specific date.

**Endpoint:** `GET /workers/{employee}/path`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `employee` | integer | Yes | Employee ID |

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
      "date_time": "2024-01-15T10:30:00Z"
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
| `accuracy` | float | GPS accuracy in meters |
| `provider` | string | GPS provider type |
| `date_time` | string | ISO 8601 timestamp |

**Notes:**
- Returns empty array if no GPS data exists for the specified date
- Points are ordered chronologically

---

### Get Worker Current Status

Get the current status of a worker including latest GPS position and active attendance session.

**Endpoint:** `GET /workers/{employee}/current-status`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `employee` | integer | Yes | Employee ID |

**Response:**

**Success (200 OK):**
```json
{
  "employee": {
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
    "date_time": "2024-01-15T10:30:00Z"
  },
  "attendance_session": {
    "id": 45,
    "date": "2024-01-15",
    "entry_time": "2024-01-15T08:00:00Z",
    "exit_time": null,
    "total_in_zone_duration": 120,
    "total_out_zone_duration": 30,
    "status": "in_progress"
  }
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `employee` | object | Employee information (id, name) |
| `latest_gps` | object\|null | Latest GPS data point (same structure as path endpoint) |
| `attendance_session` | object\|null | Active attendance session for today (if exists) |

**Attendance Session Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Session ID |
| `date` | string | Date in YYYY-MM-DD format |
| `entry_time` | string\|null | ISO 8601 timestamp of entry |
| `exit_time` | string\|null | ISO 8601 timestamp of exit |
| `total_in_zone_duration` | integer | Total minutes spent in zone |
| `total_out_zone_duration` | integer | Total minutes spent outside zone |
| `status` | string | Session status: `in_progress` or `completed` |

---

## Work Shifts

### List Work Shifts

Get all work shifts for a farm.

**Endpoint:** `GET /farms/{farm}/work-shifts`

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

**Endpoint:** `POST /farms/{farm}/work-shifts`

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

**Endpoint:** `GET /work-shifts/{workShift}`

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

**Endpoint:** `PUT /work-shifts/{workShift}` or `PATCH /work-shifts/{workShift}`

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

**Endpoint:** `DELETE /work-shifts/{workShift}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `workShift` | integer | Yes | Work Shift ID |

**Response:**

**Success (204 No Content):**

**Error Responses:**

- `400 Bad Request` - Cannot delete shift with scheduled workers

---

## Worker Shift Schedules

### List Shift Schedules (Calendar View)

Get shift schedules for a farm in calendar format, grouped by date.

**Endpoint:** `GET /farms/{farm}/shift-schedules`

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
          "employee": {
            "id": 1,
            "fname": "John",
            "lname": "Doe",
            "full_name": "John Doe"
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
| `employee` | object | Employee information |
| `shift` | object | Work shift information |
| `scheduled_date` | string | Scheduled date in YYYY-MM-DD format |
| `status` | string | Status: `scheduled`, `completed`, `missed`, `cancelled` |
| `created_at` | string | ISO 8601 timestamp |
| `updated_at` | string | ISO 8601 timestamp |

---

### Create Shift Schedule

Assign a work shift to an employee for a specific date.

**Endpoint:** `POST /shift-schedules`

**Authentication:** Required

**Request Body:**
```json
{
  "employee_id": 1,
  "shift_id": 1,
  "scheduled_date": "2024-01-15"
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `employee_id` | integer | Yes | Employee ID (must exist) |
| `shift_id` | integer | Yes | Work Shift ID (must exist) |
| `scheduled_date` | string | Yes | Date in YYYY-MM-DD format |

**Response:**

**Success (201 Created):**
```json
{
  "data": {
    "id": 1,
    "employee": {
      "id": 1,
      "fname": "John",
      "lname": "Doe",
      "full_name": "John Doe"
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
}
```

**Error Responses:**

- `400 Bad Request` - Employee must be shift-based to assign shifts
- `400 Bad Request` - Shift overlaps with existing schedule

**Notes:**
- Only employees with `work_type` = `shift_based` can be assigned shifts
- Overlapping shifts on the same date are not allowed
- Status defaults to `scheduled`

---

### Get Shift Schedule

Get a specific shift schedule.

**Endpoint:** `GET /shift-schedules/{shift_schedule}`

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
    "employee": {
      "id": 1,
      "fname": "John",
      "lname": "Doe",
      "full_name": "John Doe"
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
}
```

---

### Update Shift Schedule

Update a shift schedule.

**Endpoint:** `PUT /shift-schedules/{shift_schedule}` or `PATCH /shift-schedules/{shift_schedule}`

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
| `shift_id` | integer | No | Work Shift ID (must exist) |
| `scheduled_date` | string | No | Date in YYYY-MM-DD format |
| `status` | string | No | Status: `scheduled`, `completed`, `missed`, `cancelled` |

**Response:**

**Success (200 OK):**
```json
{
  "data": {
    "id": 1,
    "employee": {
      "id": 1,
      "fname": "John",
      "lname": "Doe",
      "full_name": "John Doe"
    },
    "shift": {
      "id": 2,
      "name": "Evening Shift",
      "start_time": "16:00",
      "end_time": "00:00",
      "work_hours": 8.00
    },
    "scheduled_date": "2024-01-16",
    "status": "completed",
    "created_at": "2024-01-01T00:00:00Z",
    "updated_at": "2024-01-15T10:30:00Z"
  }
}
```

---

### Delete Shift Schedule

Delete a shift schedule.

**Endpoint:** `DELETE /shift-schedules/{shift_schedule}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `shift_schedule` | integer | Yes | Shift Schedule ID |

**Response:**

**Success (204 No Content):**

---

## Worker Daily Reports

### List Daily Reports

Get a list of worker daily reports (inbox view).

**Endpoint:** `GET /worker-daily-reports`

**Authentication:** Required

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from_date` | string | No | Filter from date (YYYY-MM-DD) |
| `to_date` | string | No | Filter to date (YYYY-MM-DD) |
| `employee_id` | integer | No | Filter by employee ID |
| `status` | string | No | Filter by status: `pending`, `approved`, `rejected` |

**Response:**

**Success (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "employee": {
        "id": 1,
        "fname": "John",
        "lname": "Doe",
        "full_name": "John Doe"
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
| `employee` | object | Employee information |
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

**Endpoint:** `GET /worker-daily-reports/{workerDailyReport}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `workerDailyReport` | integer | Yes | Worker Daily Report ID |

**Response:**

**Success (200 OK):**
```json
{
  "data": {
    "id": 1,
    "employee": {
      "id": 1,
      "fname": "John",
      "lname": "Doe",
      "full_name": "John Doe"
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
}
```

---

### Update Daily Report

Update a daily report (admin edits).

**Endpoint:** `PATCH /worker-daily-reports/{workerDailyReport}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `workerDailyReport` | integer | Yes | Worker Daily Report ID |

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

**Success (200 OK):**
```json
{
  "data": {
    "id": 1,
    "employee": {
      "id": 1,
      "fname": "John",
      "lname": "Doe",
      "full_name": "John Doe"
    },
    "date": "2024-01-15",
    "scheduled_hours": 8.00,
    "actual_work_hours": 7.50,
    "overtime_hours": 0.00,
    "time_outside_zone": 30,
    "productivity_score": 93.75,
    "status": "approved",
    "admin_added_hours": 0.5,
    "admin_reduced_hours": 0.0,
    "notes": "Good performance",
    "approver": {
      "id": 2,
      "name": "Admin User"
    },
    "approved_at": "2024-01-15T10:30:00Z",
    "created_at": "2024-01-15T08:00:00Z",
    "updated_at": "2024-01-15T10:30:00Z"
  }
}
```

---

### Approve Daily Report

Approve a daily report.

**Endpoint:** `POST /worker-daily-reports/{workerDailyReport}/approve`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `workerDailyReport` | integer | Yes | Worker Daily Report ID |

**Response:**

**Success (200 OK):**
```json
{
  "message": "Report approved successfully",
  "report": {
    "id": 1,
    "employee": {
      "id": 1,
      "fname": "John",
      "lname": "Doe",
      "full_name": "John Doe"
    },
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
    "approver": {
      "id": 2,
      "name": "Admin User"
    },
    "approved_at": "2024-01-15T10:30:00Z",
    "created_at": "2024-01-15T08:00:00Z",
    "updated_at": "2024-01-15T10:30:00Z"
  }
}
```

**Notes:**
- Sets status to `approved`
- Records approver and approval timestamp
- Uses authenticated user as approver

---

## Worker Payroll

### Generate Payroll

Generate payroll reports for a date range. This queues a background job to process payroll calculations.

**Endpoint:** `POST /worker-payrolls/generate`

**Authentication:** Required

**Request Body:**
```json
{
  "from_date": "2024-01-01",
  "to_date": "2024-01-31",
  "employee_id": 1
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from_date` | string | Yes | Start date in YYYY-MM-DD format |
| `to_date` | string | Yes | End date in YYYY-MM-DD format (must be >= from_date) |
| `employee_id` | integer | No | Specific employee ID (optional, generates for all if omitted) |

**Response:**

**Success (202 Accepted):**
```json
{
  "message": "Payroll generation job queued successfully"
}
```

**Error Responses:**

- `422 Unprocessable Entity` - Validation errors (invalid dates, date range issues)

**Notes:**
- Payroll generation runs asynchronously in a background job
- Monthly payrolls are created for each employee in the date range
- Calculations include base wages, overtime, additions, and deductions

---

### List Payroll Reports

Get a list of monthly payroll reports.

**Endpoint:** `GET /worker-payrolls`

**Authentication:** Required

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from_date` | string | No | Filter from creation date (YYYY-MM-DD) |
| `to_date` | string | No | Filter to creation date (YYYY-MM-DD) |
| `employee_id` | integer | No | Filter by employee ID |
| `month` | integer | No | Filter by month (1-12) |
| `year` | integer | No | Filter by year (4 digits) |

**Response:**

**Success (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "employee": {
        "id": 1,
        "fname": "John",
        "lname": "Doe",
        "full_name": "John Doe"
      },
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
| `employee` | object | Employee information |
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

**Endpoint:** `GET /worker-payrolls/{workerMonthlyPayroll}`

**Authentication:** Required

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `workerMonthlyPayroll` | integer | Yes | Worker Monthly Payroll ID |

**Response:**

**Success (200 OK):**
```json
{
  "data": {
    "id": 1,
    "employee": {
      "id": 1,
      "fname": "John",
      "lname": "Doe",
      "full_name": "John Doe"
    },
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

## Human Resources Map

### Get Active Workers for HR Map

Get active workers with GPS data formatted for map display in HR dashboard.

**Endpoint:** `GET /farms/{farm}/hr/active-workers`

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
      "fname": "John",
      "lname": "Doe",
      "coordinate": {
        "lat": 34.052235,
        "lng": -118.243683,
        "altitude": 92.4
      },
      "last_update": "2024-01-15T10:30:00Z",
      "is_in_zone": true
    }
  ]
}
```

**Response Fields:**

Same as [Get Active Workers for Farm](#get-active-workers-for-farm) endpoint.

**Notes:**
- This endpoint uses the same service as the active workers endpoint but returns data in a resource collection format
- Optimized for HR map dashboard display

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
   - Solution: Ensure shifts don't overlap on the same date for the same employee

5. **Invalid Employee Type**
   - Status: `400 Bad Request`
   - Solution: Only shift-based employees can be assigned shifts

---

## Data Models

### Employee

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Employee ID |
| `farm_id` | integer | Farm ID |
| `fname` | string | First name |
| `lname` | string | Last name |
| `national_id` | string | National ID |
| `mobile` | string | Mobile number |
| `work_type` | string | Work type: `shift_based` or `hourly_based` |
| `work_days` | array | Array of work days |
| `work_hours` | decimal | Work hours |
| `start_work_time` | time | Start work time |
| `end_work_time` | time | End work time |
| `monthly_salary` | decimal | Monthly salary |
| `hourly_wage` | decimal | Hourly wage |
| `overtime_hourly_wage` | decimal | Overtime hourly wage |
| `user_id` | integer | Associated user ID |
| `is_working` | boolean | Whether employee is currently working |

### Worker Attendance Session

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Session ID |
| `employee_id` | integer | Employee ID |
| `date` | date | Session date |
| `entry_time` | datetime | Entry time |
| `exit_time` | datetime | Exit time |
| `total_in_zone_duration` | integer | Total minutes in zone |
| `total_out_zone_duration` | integer | Total minutes outside zone |
| `status` | string | Status: `in_progress` or `completed` |

### Work Shift

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Shift ID |
| `farm_id` | integer | Farm ID |
| `name` | string | Shift name |
| `start_time` | time | Start time (HH:mm) |
| `end_time` | time | End time (HH:mm) |
| `work_hours` | decimal | Work hours |

### Worker Shift Schedule

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Schedule ID |
| `employee_id` | integer | Employee ID |
| `shift_id` | integer | Work Shift ID |
| `scheduled_date` | date | Scheduled date |
| `status` | string | Status: `scheduled`, `completed`, `missed`, `cancelled` |

### Worker Daily Report

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Report ID |
| `employee_id` | integer | Employee ID |
| `date` | date | Report date |
| `scheduled_hours` | decimal | Scheduled work hours |
| `actual_work_hours` | decimal | Actual work hours |
| `overtime_hours` | decimal | Overtime hours |
| `time_outside_zone` | integer | Minutes outside zone |
| `productivity_score` | decimal | Productivity score |
| `status` | string | Status: `pending`, `approved`, `rejected` |
| `admin_added_hours` | decimal | Admin added hours |
| `admin_reduced_hours` | decimal | Admin reduced hours |
| `notes` | string | Admin notes |
| `approved_by` | integer | Approver user ID |
| `approved_at` | datetime | Approval timestamp |

### Worker Monthly Payroll

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Payroll ID |
| `employee_id` | integer | Employee ID |
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
2. **Employee Type**: Verify employee is shift-based before assigning shifts
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

The system broadcasts the following events:

- `WorkerStatusChanged`: When worker GPS status changes
- `WorkerAttendanceUpdated`: When attendance session is updated

These events can be subscribed to via Laravel's broadcasting system.

---

## Support & Contact

For API support, please contact the development team or refer to the main project documentation.

---

**Document Version:** 1.0  
**Last Updated:** 2024-01-15  
**API Version:** v1

