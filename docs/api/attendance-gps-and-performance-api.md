# Attendance GPS Report & Performance API

API documentation for frontend developers: **GPS report submission** and **user attendance performance**. Base URL: `/api`. All endpoints require authentication (Laravel Sanctum). Send `Authorization: Bearer {token}` and `Accept: application/json`.

---

## 1. Submit GPS Report

**Endpoint:** `POST /api/attendance/gps-report`

**Purpose:**  
Accepts a single GPS sample from the authenticated user’s mobile app. The backend stores the point, runs boundary detection (in/out of farm zone), and may update attendance status. Use this for live attendance tracking; the client should call it periodically while the app is in use.

**Authentication:**  
Required. The user must have attendance tracking enabled for a farm (middleware `attendance_tracking_enabled`). If not, the server responds with **403 Forbidden**.

**Request body (JSON):**

| Field        | Type    | Required | Description |
|-------------|---------|----------|-------------|
| `latitude`  | number  | Yes      | Latitude. Must be between -90 and 90. |
| `longitude` | number  | Yes      | Longitude. Must be between -180 and 180. |
| `speed`     | number  | Yes      | Speed (e.g. m/s). Must be ≥ 0. |
| `time`      | integer | Yes      | Timestamp of the sample in **milliseconds** (Unix time). |
| `exit`      | boolean | Yes      | Indicates whether the user is leaving (e.g. end of day or leaving zone). |

**Response:**

- **200 OK** — Body: `{ "success": true }`.
- **400 Bad Request** — Validation failed (e.g. missing/invalid `latitude`, `longitude`, `speed`, `time`, or `exit`). Response body contains validation errors.
- **401 Unauthorized** — Not authenticated.
- **403 Forbidden** — Attendance tracking not enabled for the current user.

---

## 2. User Attendance Performance

**Endpoint:** `GET /api/users/{user}/attendance/performance`

**Purpose:**  
Returns attendance performance for one user on one date. The **farm** is not in the URL: it is taken from the user’s **current working environment** (the farm they are set to work at). Use this to show a single user’s performance (e.g. on a user detail or attendance detail screen). For a farm-level view, use `GET /api/farms/{farm}/attendance/active-users` to get the user list, then call this endpoint per user as needed.

**Path parameter:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `user`    | integer | User ID. |

**Query parameters:**

| Parameter | Type   | Required | Description |
|-----------|--------|----------|-------------|
| `date`    | string | Yes      | Date in **Shamsi (Jalali)** format, e.g. `1403/12/04`. Required. |

**Response:**

- **200 OK** — Body: `{ "data": { ... } }` where the object has the following fields.

**Response `data` object:**

| Field                      | Type   | Description |
|----------------------------|--------|-------------|
| `id`                       | integer | User ID. |
| `name`                     | string | User’s display name (from profile). |
| `image`                    | string \| null | Profile image URL (or null). |
| `entry_time`               | string | Clock-in time in `H:i:s` (e.g. `08:30:00`), or `00:00:00` if no session. |
| `exit_time`                | string | Clock-out time in `H:i:s`, or `00:00:00` if no session or not set. |
| `required_work_duration`   | number | Required work duration (reserved; currently `0`). |
| `extra_work_duration`      | number | Extra/overtime duration (reserved; currently `0`). |
| `outside_zone_duration`    | number | Time spent outside the farm boundary (e.g. seconds). `0` if no session. |
| `efficiency`               | number | Efficiency value for the day (decimal). `0` if no session. |
| `task_based_efficiency`    | number | Task-based efficiency (reserved; currently `0`). |

- **404 Not Found** — User does not have attendance tracking enabled for their current working-environment farm. Response body includes a message to that effect.
- **401 Unauthorized** — Not authenticated.
- **400 Bad Request** — Invalid or missing `date` (e.g. not a valid Shamsi date). Response body contains validation errors.

---

## Note on farm-scoped performance

There is no endpoint of the form `/api/farms/{farm}/attendance/performance`. Performance is returned **per user** via `GET /api/users/{user}/attendance/performance`. The farm context is the user’s working environment. To show performance for “all users on this farm,” call `/api/farms/{farm}/attendance/active-users` and then call `/api/users/{user}/attendance/performance` for each user with the desired `date` query parameter.
