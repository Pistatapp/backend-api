# Users API Documentation

## Overview

The Users API provides endpoints for managing user accounts. It supports creating, reading, updating, and deleting users, and optional **attendance tracking** with a **tracking device** (mobile phone or personal GPS) when enabled.

**Base URL:** `/api/users`

**Authentication:** All endpoints require `auth:sanctum` and `ensure.username`. Send the Bearer token in the `Authorization` header.

**Authorization:**  
- List/Create: user must have `manage-users` permission.  
- Show/Update/Delete: user must be allowed by policy (e.g. `update` the target user).

---

## Table of Contents

1. [List Users](#1-list-users)
2. [Create User](#2-create-user)
3. [Get User](#3-get-user)
4. [Update User](#4-update-user)
5. [Delete User](#5-delete-user)
6. [Request & Response Reference](#6-request--response-reference)
7. [Farm Resource: attendance_tracking_enabled](#7-farm-resource-attendance_tracking_enabled)
8. [Errors](#errors)
9. [Example Usage](#example-usage)

---

## 1. List Users

Returns a paginated list of users. The authenticated user is excluded. Non-root users only see users in their working-environment farm.

### Endpoint

```http
GET /api/users
```

### Query Parameters

| Parameter | Type   | Required | Description |
|-----------|--------|----------|-------------|
| page      | integer| No       | Page number (simple pagination). |

### Response

**Success (200 OK)**

```json
{
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "mobile": "09123456789",
      "username": null,
      "last_activity_at": "1403/11/25 14:30:00",
      "role": "operator",
      "labour": null
    },
    {
      "id": 2,
      "name": "Jane Smith",
      "mobile": "09187654321",
      "username": "labour_09187654321",
      "last_activity_at": "1403/11/24 08:00:00",
      "role": "labour",
      "labour": {
        "id": 1,
        "name": "Jane Smith",
        "personnel_number": "EMP-001",
        "mobile": "09187654321",
        "work_type": "administrative",
        "work_days": ["saturday", "sunday", "monday"],
        "work_hours": 8,
        "start_work_time": "08:00",
        "end_work_time": "16:00",
        "hourly_wage": 150000,
        "overtime_hourly_wage": 200000,
        "attendence_tracking_enabled": true,
        "imei": null,
        "image": "http://example.com/storage/...",
        "is_working": false,
        "current_shift": null,
        "shift_schedules": [],
        "teams": [],
        "created_at": "1403/11/01",
        "can": { "update": true, "delete": true }
      }
    }
  ],
  "links": {
    "first": "http://example.com/api/users?page=1",
    "last": null,
    "prev": null,
    "next": "http://example.com/api/users?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "path": "http://example.com/api/users",
    "per_page": 15,
    "to": 15
  }
}
```

### Notes

- Uses **simple pagination** (no total count).
- Role in the response reflects the user’s role in the **working environment** farm when applicable.

---

## 2. Create User

Creates a new user, assigns a role and farm, and optionally creates **attendance tracking** and a **tracking device** when `attendance_tracking_enabled` is `true`.

### Endpoint

```http
POST /api/users
```

### Request Headers

```http
Authorization: Bearer {token}
Content-Type: application/json
```

For profile image upload use `multipart/form-data` and include an `image` file.

### Request Body

#### Common Fields (all users)

| Field   | Type    | Required | Validation | Description |
|---------|---------|----------|------------|-------------|
| name    | string  | Yes      | max:255    | Full name. |
| mobile  | string  | Yes      | `ir_mobile:zero`, unique in `users.mobile` | Iranian mobile with leading zero (e.g. `09123456789`). |
| role    | string  | Yes      | exists in `roles.name`, allowed by assignment rules | User role. |
| farm_id | integer | Yes      | exists in `farms.id`, allowed for current user | Farm to attach the user to. |

#### When `attendance_tracking_enabled` is `true`

If `attendance_tracking_enabled` is sent as `true`, the following fields are **required** and a tracking device is created or updated.

| Field | Type | Required | Validation | Description |
|-------|------|----------|------------|-------------|
| attendance_tracking_enabled | boolean | Yes (when using attendance) | `true` or `false` | Must be `true` to send attendance/tracking fields. |
| work_type | string | Yes | `administrative` \| `shift_based` | Schedule type. |
| work_days | array | Conditional | Required if `work_type` = `administrative`. Prohibited if `shift_based`. | e.g. `["saturday", "sunday", "monday"]`. |
| work_hours | number | Conditional | 1–24, required if `work_type` = `administrative` | Daily work hours. |
| start_work_time | string | Conditional | `H:i`, required if `work_type` = `administrative`, must be before `end_work_time` | e.g. `"08:00"`. |
| end_work_time | string | Conditional | `H:i`, after `start_work_time`, required if `work_type` = `administrative` | e.g. `"16:00"`. |
| hourly_wage | integer | Yes | min:1 | Hourly wage. |
| overtime_hourly_wage | integer | Yes | min:1 | Overtime hourly wage. |
| tracking_device | object | Yes | See below | Device used for attendance (mobile or GPS). |
| image | file | No | image, max 1024 KB | Profile image. |

**tracking_device** (required when attendance tracking is enabled):

| Field | Type | Required | Validation | Description |
|-------|------|----------|------------|-------------|
| tracking_device.type | string | Yes | `mobile_phone` \| `personal_gps` | Device type. |
| tracking_device.device_fingerprint | string | If `type` = `mobile_phone` | 1–255 chars | Unique device fingerprint from client. |
| tracking_device.sim_number | string | Yes | `ir_mobile:zero` | Iranian SIM number (e.g. `09123456789`). |
| tracking_device.imei | string | Yes | Exactly 15 digits, `[0-9]{15}` | Device IMEI. |

### Example: Create user without attendance tracking

```json
{
  "name": "John Doe",
  "mobile": "09123456789",
  "role": "operator",
  "farm_id": 1
}
```

### Example: Create user with attendance tracking (administrative)

```json
{
  "name": "Jane Smith",
  "mobile": "09187654321",
  "role": "labour",
  "farm_id": 1,
  "attendance_tracking_enabled": true,
  "work_type": "administrative",
  "work_days": ["saturday", "sunday", "monday", "tuesday", "wednesday"],
  "work_hours": 8,
  "start_work_time": "08:00",
  "end_work_time": "16:00",
  "hourly_wage": 150000,
  "overtime_hourly_wage": 200000,
  "tracking_device": {
    "type": "mobile_phone",
    "device_fingerprint": "unique-client-device-fingerprint-string",
    "sim_number": "09187654321",
    "imei": "123456789012345"
  }
}
```

### Example: Create user with attendance tracking (shift-based)

```json
{
  "name": "Mike Johnson",
  "mobile": "09191234567",
  "role": "labour",
  "farm_id": 1,
  "attendance_tracking_enabled": true,
  "work_type": "shift_based",
  "hourly_wage": 180000,
  "overtime_hourly_wage": 250000,
  "tracking_device": {
    "type": "personal_gps",
    "sim_number": "09191234567",
    "imei": "987654321098765"
  }
}
```

**Note:** For `personal_gps`, `tracking_device.device_fingerprint` is optional. For `mobile_phone` it is required.

### Response

**Success (201 Created)**

```json
{
  "data": {
    "id": 10,
    "name": "Jane Smith",
    "mobile": "09187654321",
    "username": "labour_09187654321",
    "last_activity_at": null,
    "role": "labour",
    "labour": { ... }
  }
}
```

### Side effects when `attendance_tracking_enabled` is true

- One **attendance tracking** record is created/updated for the user (linked to the given `farm_id`), with `enabled` set to `true`.
- One **GPS device** (worker device) is created or updated for the user from `tracking_device` (type, imei, sim_number, device_fingerprint when type is `mobile_phone`).

---

## 3. Get User

Returns a single user by ID.

### Endpoint

```http
GET /api/users/{user}
```

### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| user | integer | Yes | User ID. |

### Response

**Success (200 OK)**

```json
{
  "data": {
    "id": 10,
    "name": "Jane Smith",
    "mobile": "09187654321",
    "username": "labour_09187654321",
    "last_activity_at": "1403/11/25 09:00:00",
    "role": "labour",
    "labour": { ... }
  }
}
```

---

## 4. Update User

Updates an existing user (profile, mobile, role, farm). If `attendance_tracking_enabled` is `true`, attendance tracking and the tracking device are created or updated using the same rules as create.

### Endpoint

```http
PUT /api/users/{user}
PATCH /api/users/{user}
```

### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| user | integer | Yes | User ID. |

### Request Body

Same structure as [Create User](#2-create-user).  
- **mobile**: uniqueness is checked excluding the user being updated.  
- When `attendance_tracking_enabled` is `true`, all attendance and `tracking_device` fields listed in Create User are required and validated the same way.

### Response

**Success (200 OK)**

Same shape as [Get User](#3-get-user) with updated data.

### Notes

- Role and farm are synced (user is attached only to the provided `farm_id` with the given `role`).
- If attendance tracking is enabled, the existing worker GPS device for this user is updated, or a new one is created.

---

## 5. Delete User

Permanently deletes a user and related attendance tracking, profile, and farm attachments.

### Endpoint

```http
DELETE /api/users/{user}
```

### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| user | integer | Yes | User ID. |

### Response

**Success (204 No Content)**

No response body.

### Notes

- Deletes: user’s attendance tracking, profile, and farm pivot rows. The user record itself is removed.

---

## 6. Request & Response Reference

### User resource (response)

| Field | Type | Description |
|-------|------|-------------|
| id | integer | User ID. |
| name | string | Full name (from profile). |
| mobile | string | Iranian mobile. |
| username | string \| null | Set for labour as `labour_{mobile}`. |
| last_activity_at | string \| null | Jalali datetime. |
| role | string | Role name (in working-environment context when applicable). |
| labour | object \| null | Present when role is `labour`; see Labour resource. |

### Work types (attendance)

| Value | Description |
|-------|-------------|
| administrative | Fixed days and hours (work_days, work_hours, start_work_time, end_work_time). |
| shift_based | No fixed schedule; administrative-time fields are cleared. |

### Roles

Roles are enforced by the backend (e.g. `admin`, `operator`, `labour`, etc.). Only roles allowed by the current user’s permissions can be assigned.

---

## 7. Farm Resource: attendance_tracking_enabled

When you fetch a **single farm** (e.g. `GET /api/farms/{farm}`), the response is a **Farm resource**. That resource includes a boolean field that indicates whether **attendance tracking is enabled for the current user in that farm**:

| Field | Type | Description |
|-------|------|-------------|
| attendance_tracking_enabled | boolean | `true` if the authenticated user has an attendance tracking record for this farm with `enabled` = true; otherwise `false`. |

This is derived from the `attendance_trackings` table (user_id + farm_id + enabled). Use it on the frontend to show whether the current user has attendance tracking on for the farm they are viewing (e.g. in farm detail or settings).

**Example farm response (relevant part):**

```json
{
  "data": {
    "id": 1,
    "name": "Main Farm",
    "attendance_tracking_enabled": true,
    ...
  }
}
```

---

## Errors

### HTTP status codes

| Code | Meaning |
|------|--------|
| 200 | OK |
| 201 | Created |
| 204 | No Content |
| 401 | Unauthenticated (missing or invalid token) |
| 403 | Forbidden (e.g. no `manage-users` or policy denial) |
| 404 | User not found |
| 422 | Validation error |

### Validation error (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "mobile": ["The mobile has already been taken."],
    "tracking_device.device_fingerprint": ["The tracking device.device fingerprint field is required when tracking device.type is mobile_phone."],
    "tracking_device.imei": ["The tracking device.imei must be 15 digits."]
  }
}
```

---

## Example Usage

### List users

```javascript
const { data } = await axios.get('/api/users', {
  headers: { Authorization: `Bearer ${token}` }
});
```

### Create user with attendance tracking (JSON)

```javascript
await axios.post('/api/users', {
  name: 'Jane Smith',
  mobile: '09187654321',
  role: 'labour',
  farm_id: 1,
  attendance_tracking_enabled: true,
  work_type: 'administrative',
  work_days: ['saturday', 'sunday', 'monday'],
  work_hours: 8,
  start_work_time: '08:00',
  end_work_time: '16:00',
  hourly_wage: 150000,
  overtime_hourly_wage: 200000,
  tracking_device: {
    type: 'mobile_phone',
    device_fingerprint: 'device-fingerprint-from-client',
    sim_number: '09187654321',
    imei: '123456789012345'
  }
}, {
  headers: {
    Authorization: `Bearer ${token}`,
    'Content-Type': 'application/json'
  }
});
```

### Create user with image (multipart)

```javascript
const formData = new FormData();
formData.append('name', 'Jane Smith');
formData.append('mobile', '09187654321');
formData.append('role', 'labour');
formData.append('farm_id', '1');
formData.append('attendance_tracking_enabled', 'true');
formData.append('work_type', 'administrative');
formData.append('work_days[]', 'saturday');
formData.append('work_days[]', 'sunday');
formData.append('work_hours', '8');
formData.append('start_work_time', '08:00');
formData.append('end_work_time', '16:00');
formData.append('hourly_wage', '150000');
formData.append('overtime_hourly_wage', '200000');
formData.append('tracking_device[type]', 'mobile_phone');
formData.append('tracking_device[device_fingerprint]', 'fp-xxx');
formData.append('tracking_device[sim_number]', '09187654321');
formData.append('tracking_device[imei]', '123456789012345');
formData.append('image', imageFile);

await axios.post('/api/users', formData, {
  headers: {
    Authorization: `Bearer ${token}`,
    'Content-Type': 'multipart/form-data'
  }
});
```

### Get single user

```javascript
const { data } = await axios.get(`/api/users/${userId}`, {
  headers: { Authorization: `Bearer ${token}` }
});
```

### Update user

```javascript
await axios.put(`/api/users/${userId}`, {
  name: 'Jane Smith Updated',
  mobile: '09187654321',
  role: 'labour',
  farm_id: 1,
  attendance_tracking_enabled: true,
  work_type: 'shift_based',
  hourly_wage: 200000,
  overtime_hourly_wage: 250000,
  tracking_device: {
    type: 'mobile_phone',
    device_fingerprint: 'new-fingerprint',
    sim_number: '09187654321',
    imei: '123456789012345'
  }
}, {
  headers: {
    Authorization: `Bearer ${token}`,
    'Content-Type': 'application/json'
  }
});
```

### Delete user

```javascript
await axios.delete(`/api/users/${userId}`, {
  headers: { Authorization: `Bearer ${token}` }
});
```

### Check attendance tracking for current user in a farm

```javascript
// After GET /api/farms/{farmId}
const farm = response.data.data;
if (farm.attendance_tracking_enabled) {
  // Show attendance-related UI for this farm
}
```

---

**Version:** 2.0  
**Last updated:** February 2026
