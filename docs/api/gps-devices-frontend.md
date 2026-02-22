# GPS Devices API — Frontend Documentation

This document describes how to use the **GPS Devices** API from the frontend: endpoints, request/response shapes, validation, authorization, and usage examples.

---

## Overview

| Item | Value |
|------|--------|
| **Base path** | `/api/gps_devices` |
| **Authentication** | Required — `Bearer` token (Laravel Sanctum) |
| **Content type** | `application/json` |
| **Authorization** | Role-based: `root` (full CRUD), `admin` (list/view only for devices in their farms) |

---

## Authentication

All requests must include a valid Sanctum token:

```http
Authorization: Bearer {your-sanctum-token}
Accept: application/json
Content-Type: application/json
```

---

## Endpoints Summary

| Method | Endpoint | Description | Allowed roles |
|--------|----------|-------------|----------------|
| `GET` | `/api/gps_devices` | List GPS devices (paginated) | root, admin |
| `POST` | `/api/gps_devices` | Create a GPS device | root only |
| `GET` | `/api/gps_devices/{id}` | Get a single device | root, admin* |
| `PUT` / `PATCH` | `/api/gps_devices/{id}` | Update a device | root only |
| `DELETE` | `/api/gps_devices/{id}` | Delete a device | root only |

\* Admin can only view devices whose owner belongs to one of their farms.

---

## List GPS Devices

**Request**

```http
GET /api/gps_devices
```

**Query parameters (optional)**

- `page` — Page number for simple pagination (e.g. `?page=2`).

**Scoping**

- **Root:** sees all devices.
- **Non-root:** sees only devices where `user_id` equals the authenticated user's ID.

**Response:** `200 OK`

```json
{
  "data": [
    {
      "id": 1,
      "user": {
        "id": 10,
        "username": "john",
        "mobile": "09123456789"
      },
      "name": "Device Alpha",
      "imei": "123456789012345",
      "sim_number": "09121234567",
      "created_at": "1402-11-25 14:30:00",
      "can": {
        "update": true,
        "delete": true
      }
    }
  ],
  "links": {
    "first": "http://example.com/api/gps_devices?page=1",
    "last": null,
    "prev": null,
    "next": "http://example.com/api/gps_devices?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "path": "http://example.com/api/gps_devices",
    "per_page": 15,
    "to": 15
  }
}
```

`can.update` and `can.delete` are computed from the policy; use them to show/hide edit and delete actions in the UI.

---

## Get Single GPS Device

**Request**

```http
GET /api/gps_devices/{id}
```

**Response:** `200 OK`

```json
{
  "data": {
    "id": 1,
    "user": {
      "id": 10,
      "username": "john",
      "mobile": "09123456789"
    },
    "name": "Device Alpha",
    "imei": "123456789012345",
    "sim_number": "09121234567",
    "created_at": "1402-11-25 14:30:00",
    "can": {
      "update": true,
      "delete": true
    }
  }
}
```

**Errors**

- `403 Forbidden` — Not allowed to view this device (e.g. admin and device owner not in their farms).
- `404 Not Found` — Device does not exist.

---

## Create GPS Device

**Request**

```http
POST /api/gps_devices
Content-Type: application/json
```

**Body**

| Field | Type | Required | Rules |
|-------|------|----------|--------|
| `user_id` | integer | Yes | Must exist in `users.id` |
| `name` | string | Yes | Max 255 characters |
| `imei` | string | Yes | Max 255; must be unique in `gps_devices` |
| `sim_number` | string | Yes | Max 255; must be unique in `gps_devices` |

**Example**

```json
{
  "user_id": 10,
  "name": "Tractor GPS #1",
  "imei": "123456789012345",
  "sim_number": "09121234567"
}
```

**Response:** `201 Created`

```json
{
  "data": {
    "id": 5,
    "user": {
      "id": 10,
      "username": "john",
      "mobile": "09123456789"
    },
    "name": "Tractor GPS #1",
    "imei": "123456789012345",
    "sim_number": "09121234567",
    "created_at": "1402-11-25 15:00:00",
    "can": {
      "update": true,
      "delete": true
    }
  }
}
```

**Validation errors:** `422 Unprocessable Entity`

```json
{
  "message": "The imei has already been taken.",
  "errors": {
    "imei": ["The imei has already been taken."],
    "sim_number": ["The sim number has already been taken."]
  }
}
```

**Authorization:** `403 Forbidden` if the user is not `root`.

---

## Update GPS Device

**Request**

```http
PUT /api/gps_devices/{id}
Content-Type: application/json
```

Or:

```http
PATCH /api/gps_devices/{id}
Content-Type: application/json
```

**Body**

Same as create: all fields required on update.

| Field | Type | Required | Rules |
|-------|------|----------|--------|
| `user_id` | integer | Yes | Must exist in `users.id` |
| `name` | string | Yes | Max 255 characters |
| `imei` | string | Yes | Max 255; unique in `gps_devices` except current record |
| `sim_number` | string | Yes | Max 255; unique in `gps_devices` except current record |

**Example**

```json
{
  "user_id": 10,
  "name": "Tractor GPS #1 (Updated)",
  "imei": "123456789012345",
  "sim_number": "09121234567"
}
```

**Response:** `200 OK`

Same shape as the single-device response (see **Get Single GPS Device**).

**Errors**

- `403 Forbidden` — User is not `root`.
- `404 Not Found` — Device does not exist.
- `422 Unprocessable Entity` — Validation failed (e.g. duplicate `imei` or `sim_number`).

---

## Delete GPS Device

**Request**

```http
DELETE /api/gps_devices/{id}
```

**Response:** `204 No Content`

No body.

**Errors**

- `403 Forbidden` — User is not `root`.
- `404 Not Found` — Device does not exist.

---

## Error Responses (General)

| Status | Meaning |
|--------|--------|
| `401 Unauthorized` | Missing or invalid token. |
| `403 Forbidden` | Authenticated but not allowed (policy). |
| `404 Not Found` | Resource not found. |
| `422 Unprocessable Entity` | Validation failed; see `errors` in body. |
| `500 Internal Server Error` | Server error. |

Typical error body:

```json
{
  "message": "Human-readable message",
  "errors": {
    "field_name": ["Error 1", "Error 2"]
  }
}
```

---

## Frontend Usage Examples

### Fetch (vanilla)

```javascript
const API_BASE = '/api';

async function listGpsDevices(page = 1) {
  const res = await fetch(`${API_BASE}/gps_devices?page=${page}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    },
  });
  if (!res.ok) throw new Error(await res.text());
  return res.json();
}

async function createGpsDevice(payload) {
  const res = await fetch(`${API_BASE}/gps_devices`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.message || 'Create failed');
  return data;
}

async function updateGpsDevice(id, payload) {
  const res = await fetch(`${API_BASE}/gps_devices/${id}`, {
    method: 'PUT',
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.message || 'Update failed');
  return data;
}

async function deleteGpsDevice(id) {
  const res = await fetch(`${API_BASE}/gps_devices/${id}`, {
    method: 'DELETE',
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  if (res.status !== 204 && !res.ok) throw new Error('Delete failed');
}
```

### Axios

```javascript
import axios from 'axios';

const api = axios.create({
  baseURL: '/api',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

api.interceptors.request.use((config) => {
  config.headers.Authorization = `Bearer ${getToken()}`;
  return config;
});

export const gpsDevicesApi = {
  list: (params) => api.get('/gps_devices', { params }).then((r) => r.data),
  get: (id) => api.get(`/gps_devices/${id}`).then((r) => r.data),
  create: (data) => api.post('/gps_devices', data).then((r) => r.data),
  update: (id, data) => api.put(`/gps_devices/${id}`, data).then((r) => r.data),
  delete: (id) => api.delete(`/gps_devices/${id}`),
};
```

### Handling validation errors (React-style)

```javascript
async function handleSubmit(values) {
  try {
    await createGpsDevice(values);
    // success
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      const errors = err.response.data.errors;
      // Map to form fields, e.g. setFieldError('imei', errors.imei?.[0])
      return errors;
    }
    throw err;
  }
}
```

---

## TypeScript types (optional)

```typescript
export interface GpsDeviceUser {
  id: number;
  username: string | null;
  mobile: string;
}

export interface GpsDevice {
  id: number;
  user: GpsDeviceUser | null;
  name: string;
  imei: string;
  sim_number: string;
  created_at: string;
  can: {
    update: boolean;
    delete: boolean;
  };
}

export interface GpsDeviceCreatePayload {
  user_id: number;
  name: string;
  imei: string;
  sim_number: string;
}

export type GpsDeviceUpdatePayload = GpsDeviceCreatePayload;

export interface GpsDeviceListResponse {
  data: GpsDevice[];
  links: {
    first: string;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
  meta: {
    current_page: number;
    from: number;
    path: string;
    per_page: number;
    to: number;
  };
}

export interface GpsDeviceSingleResponse {
  data: GpsDevice;
}
```

---

## UI notes

1. **Pagination:** List uses simple pagination; use `links.next` / `links.prev` and `meta.current_page`, `meta.per_page` for navigation.
2. **Permissions:** Use `data.can.update` and `data.can.delete` to show/hide edit and delete buttons.
3. **Uniqueness:** Validate or check `imei` and `sim_number` on create/update; show `errors.imei` / `errors.sim_number` from 422 responses.
4. **Dates:** `created_at` is returned in Jalali format (`Y-m-d H:i:s`); parse or display accordingly if you need a calendar/date picker.
