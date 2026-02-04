# Device Connection Request Routes (Root Only)

## Overview

This section contains API endpoints for managing device connection requests within the system. These routes are restricted to users with the **root** role and are protected by Laravel Sanctum authentication and username verification middleware.

**Base URL:** `/api`

**Middleware Stack:**
- `auth:sanctum` - Requires authentication via Laravel Sanctum
- `ensure.username` - Ensures user has set a username
- `role:root` - Restricts access to root users only

---

## Endpoints

#### List All Connection Requests
```http
GET /api/device-connection-requests
```

**Description:** Retrieves all pending and historical device connection requests.

**Authorization:** Root role required

**Response:** Returns a collection of device connection request resources

---

#### Approve Connection Request
```http
POST /api/device-connection-requests/{deviceConnectionRequest}/approve
```

**Description:** Approves a pending device connection request, allowing the device to connect to the system.

**Authorization:** Root role required

**Path Parameters:**
- `deviceConnectionRequest` (required) - The connection request ID

**Response:** Returns the approved connection request with updated status

---

#### Reject Connection Request
```http
POST /api/device-connection-requests/{deviceConnectionRequest}/reject
```

**Description:** Rejects a pending device connection request, preventing the device from connecting to the system.

**Authorization:** Root role required

**Path Parameters:**
- `deviceConnectionRequest` (required) - The connection request ID

**Response:** Returns the rejected connection request with updated status

---

## Controllers

- **DeviceConnectionRequestController** (`App\Http\Controllers\Api\DeviceConnectionRequestController`)
  - Manages device connection request workflows (list, approve, reject)

---

## Security & Access Control

### Authentication Requirements
All endpoints require:
1. Valid authentication token (Laravel Sanctum)
2. User must have completed username setup
3. User must have **root** role assigned

### Authorization Flow
```
Request → auth:sanctum → ensure.username → role:root → Controller
```

If any middleware check fails, the request will be rejected with appropriate HTTP status code (401 Unauthorized or 403 Forbidden).

---

## Usage Examples

### Example: Approve a Device Connection Request

**Request:**
```http
POST /api/device-connection-requests/123/approve
Authorization: Bearer {your-token}
Content-Type: application/json
```

**Success Response:**
```json
{
  "id": 123,
  "device_id": "ABC123",
  "status": "approved",
  "approved_at": "2026-02-03T10:30:00Z",
  "approved_by": 1
}
```

---

## Error Responses

### Common Error Codes

| Status Code | Description |
|------------|-------------|
| `401 Unauthorized` | Missing or invalid authentication token |
| `403 Forbidden` | User does not have root role |
| `404 Not Found` | Connection request not found |
| `422 Unprocessable Entity` | Validation errors in request data |
| `500 Internal Server Error` | Server-side error |

### Example Error Response
```json
{
  "message": "Forbidden. Root access required.",
  "errors": {
    "authorization": ["You do not have permission to perform this action."]
  }
}
```

---

## Related Routes

### Worker Device Management
For farm-level device management (admin role), see:
- `GET /api/farms/{farm}/worker-devices`
- `PUT /api/worker-devices/{device}/assign`
- `PUT /api/worker-devices/{device}/unassign`

---

## Notes

- All routes follow RESTful API conventions
- Responses follow Laravel API Resource format
- Route model binding is used for automatic model resolution
- All date/time values are in ISO 8601 format (UTC)
