# Users API Documentation

## Overview

The Users API provides endpoints for managing user accounts in the system. This includes creating, reading, updating, and deleting users, as well as managing labour-specific data for users with the "labour" role.

**Base URL:** `/api/users`

**Authentication:** All endpoints require authentication via Sanctum token (Bearer token).

---

## 1. List Users

Retrieve a paginated list of users with optional search functionality.

### Endpoint

```
GET /api/users
```

### Query Parameters

| Parameter | Type   | Required | Description                                                          |
|-----------|--------|----------|----------------------------------------------------------------------|
| search    | string | No       | Search users by mobile number. Returns all matches without pagination |

### Response

**Success (200 OK)**

```json
{
  "data": [
    {
      "id": 1,
      "username": "user123",
      "last_activity_at": "1402/10/15 14:30:25",
      "profile": {
        "name": "John Doe"
      },
      "role": "admin",
      "farms": {
        "id": 1,
        "name": "Farm Name"
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

- Excludes the authenticated user from results
- If search parameter is provided, excludes super-admin users from results
- Admin and super-admin users only see users they created
- Returns simple pagination (no total count)

---

## 2. Create User

Create a new user account. If the role is "labour", additional labour-specific data is required and a labour record will be automatically created.

### Endpoint

```
POST /api/users
```

### Request Headers

```
Content-Type: application/json
Authorization: Bearer {token}
```

For labour users with image upload:
```
Content-Type: multipart/form-data
Authorization: Bearer {token}
```

### Request Body

#### Common Fields (All Users)

| Field    | Type    | Required | Validation                        | Description                          |
|----------|---------|----------|-----------------------------------|--------------------------------------|
| name     | string  | Yes      | max:255                           | User's full name                     |
| mobile   | string  | Yes      | Iranian mobile format (zero prefix) | User's mobile number                 |
| role     | string  | Yes      | Must exist in roles table         | User role (admin, operator, etc.)    |
| farm_id  | integer | Yes      | Must exist in farms table         | Farm ID to assign user to            |

#### Additional Fields for Labour Role

When `role` is set to `"labour"`, the following fields are also required:

| Field                        | Type    | Required | Validation                                    | Description                                |
|------------------------------|---------|----------|-----------------------------------------------|--------------------------------------------|
| personnel_number             | string  | No       | max:255, unique in labours                    | Employee personnel number                  |
| work_type                    | string  | Yes      | `administrative` or `shift_based`             | Type of work schedule                      |
| work_days                    | array   | Conditional | Required if work_type=administrative        | Working days (e.g., ["saturday", "sunday"]) |
| work_hours                   | number  | Conditional | 1-24, required if work_type=administrative  | Daily working hours                        |
| start_work_time              | string  | Conditional | H:i format, required if work_type=administrative | Work start time (e.g., "08:00")           |
| end_work_time                | string  | Conditional | H:i format, after start_work_time           | Work end time (e.g., "16:00")              |
| hourly_wage                  | integer | Yes      | min:1                                         | Hourly wage amount                         |
| overtime_hourly_wage         | integer | Yes      | min:1                                         | Overtime hourly wage amount                |
| attendence_tracking_enabled  | boolean | Yes      | true/false                                    | Enable attendance tracking                 |
| image                        | file    | No       | image file, max:1024KB                        | Profile image                              |
| imei                         | string  | No       | max:255                                       | Device IMEI for tracking                   |

### Example Requests

#### Standard User (Non-Labour)

```json
{
  "name": "John Doe",
  "mobile": "09123456789",
  "role": "operator",
  "farm_id": 1
}
```

#### Labour User (Administrative Work Type)

```json
{
  "name": "Jane Smith",
  "mobile": "09187654321",
  "role": "labour",
  "farm_id": 1,
  "personnel_number": "EMP-001",
  "work_type": "administrative",
  "work_days": ["saturday", "sunday", "monday", "tuesday", "wednesday"],
  "work_hours": 8,
  "start_work_time": "08:00",
  "end_work_time": "16:00",
  "hourly_wage": 150000,
  "overtime_hourly_wage": 200000,
  "attendence_tracking_enabled": true,
  "imei": "123456789012345"
}
```

#### Labour User (Shift-Based Work Type)

```json
{
  "name": "Mike Johnson",
  "mobile": "09191234567",
  "role": "labour",
  "farm_id": 1,
  "personnel_number": "EMP-002",
  "work_type": "shift_based",
  "hourly_wage": 150000,
  "overtime_hourly_wage": 200000,
  "attendence_tracking_enabled": true
}
```

### Response

**Success (201 Created)**

```json
{
  "data": {
    "id": 10,
    "username": "user_10",
    "last_activity_at": "1402/10/15 14:35:00",
    "profile": {
      "name": "John Doe"
    },
    "role": "operator",
    "farms": {
      "id": 1,
      "name": "Farm Name"
    }
  }
}
```

### Important Notes

- **Role Permissions**: Users can only assign roles below their own hierarchy level
- **Farm Access**: Users can only assign farms they have access to
- **Mobile Number**: Must be unique Iranian mobile with zero prefix (e.g., 09123456789)

---

## 3. Get User Details

Retrieve detailed information about a specific user.

### Endpoint

```
GET /api/users/{id}
```

### URL Parameters

| Parameter | Type    | Required | Description |
|-----------|---------|----------|-------------|
| id        | integer | Yes      | User ID     |

### Response

**Success (200 OK)**

```json
{
  "data": {
    "id": 10,
    "username": "user_10",
    "last_activity_at": "1402/10/15 14:35:00",
    "profile": {
      "name": "John Doe"
    },
    "role": "operator",
    "farms": {
      "id": 1,
      "name": "Farm Name"
    }
  }
}
```

---

## 4. Update User

Update an existing user's information. If the user has the "labour" role, labour-specific data can also be updated.

### Endpoint

```
PUT /api/users/{id}
PATCH /api/users/{id}
```

### URL Parameters

| Parameter | Type    | Required | Description |
|-----------|---------|----------|-------------|
| id        | integer | Yes      | User ID     |

### Request Headers

```
Content-Type: application/json
Authorization: Bearer {token}
```

For labour users with image upload:
```
Content-Type: multipart/form-data
Authorization: Bearer {token}
```

### Request Body

The request body follows the same structure as [Create User](#2-create-user), with the same validation rules.

**Note:** Mobile number uniqueness validation excludes the current user being updated.

### Example Request

```json
{
  "name": "John Doe Updated",
  "mobile": "09123456789",
  "role": "admin",
  "farm_id": 2
}
```

### Response

**Success (200 OK)**

```json
{
  "data": {
    "id": 10,
    "username": "user_10",
    "last_activity_at": "1402/10/15 15:00:00",
    "profile": {
      "name": "John Doe Updated"
    },
    "role": "admin",
    "farms": {
      "id": 2,
      "name": "New Farm Name"
    }
  }
}
```

### Notes

- If updating a non-labour user to labour role, a labour record will be created
- If updating a labour user, the existing labour record will be updated
- When updating labour image, the old image is automatically deleted
- Farm association is synced (replaced) with the new farm_id

---

## 5. Delete User

Delete a user account from the system.

### Endpoint

```
DELETE /api/users/{id}
```

### URL Parameters

| Parameter | Type    | Required | Description |
|-----------|---------|----------|-------------|
| id        | integer | Yes      | User ID     |

### Response

**Success (204 No Content)**

No response body.

### Notes

- This is a soft delete (user record is marked as deleted but not removed from database)
- Associated labour record (if exists) will also be deleted
- User's relationships (farms, profile) are preserved in the database

---

## Data Models

### User Resource

```json
{
  "id": 10,
  "username": "user_10",
  "last_activity_at": "1402/10/15 14:35:00",
  "profile": {
    "name": "John Doe"
  },
  "role": "operator",
  "farms": {
    "id": 1,
    "name": "Farm Name"
  }
}
```

| Field            | Type   | Description                                |
|------------------|--------|--------------------------------------------|
| id               | integer| User unique identifier                     |
| username         | string | Username (unique)                          |
| last_activity_at | string | Last activity timestamp (Jalali format)    |
| profile          | object | User profile information                   |
| profile.name     | string | User's full name                           |
| role             | string | User's role name                           |
| farms            | object | Farm the user has access to                |
| farms.id         | integer| Farm ID                                    |
| farms.name       | string | Farm name                                  |

### Work Types

| Value          | Description                                        |
|----------------|----------------------------------------------------|
| administrative | Fixed schedule with specific days and hours        |
| shift_based    | Flexible shift-based schedule                      |

### Available Roles

The available roles and their hierarchy:

- `root` (System Administrator)
- `super-admin` (Super Administrator)
- `admin` (Farm Administrator)
- `operator` (Farm Operator)
- `viewer` (View-only access)
- `consultant` (Consultant)
- `inspector` (Inspector)
- `labour` (Labour/Worker)

---

## Error Responses

### Common HTTP Status Codes

| Status Code | Description |
|-------------|-------------|
| 200 | Success |
| 201 | Created successfully |
| 204 | Deleted successfully (no content) |
| 401 | Unauthenticated |
| 403 | Unauthorized action |
| 404 | Resource not found |
| 422 | Validation error |

### Validation Error Example

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "mobile": ["The mobile has already been taken."],
    "work_days": ["The work days field is required when work type is administrative."]
  }
}
```

---

## Additional Notes

- **Images**: Stored in `storage/app/public/labours`, max 1024 KB
- **Pagination**: 15 users per page, simple pagination (next/prev only)
- **Search**: Performed on mobile number only
- **Timestamps**: Returned in Jalali format (`YYYY/MM/DD HH:MM:SS`)
- **Farm Association**: One farm per user, replaced on update

---

## Example Usage with JavaScript (Axios)

### List Users

```javascript
const response = await axios.get('/api/users', {
  headers: {
    'Authorization': `Bearer ${token}`
  }
});
```

### Search Users

```javascript
const response = await axios.get('/api/users', {
  params: {
    search: '0912'
  },
  headers: {
    'Authorization': `Bearer ${token}`
  }
});
```

### Create Standard User

```javascript
const response = await axios.post('/api/users', {
  name: 'John Doe',
  mobile: '09123456789',
  role: 'operator',
  farm_id: 1
}, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json'
  }
});
```

### Create Labour User with Image

```javascript
const formData = new FormData();
formData.append('name', 'Jane Smith');
formData.append('mobile', '09187654321');
formData.append('role', 'labour');
formData.append('farm_id', '1');
formData.append('personnel_number', 'EMP-001');
formData.append('work_type', 'administrative');
formData.append('work_days[]', 'saturday');
formData.append('work_days[]', 'sunday');
formData.append('work_days[]', 'monday');
formData.append('work_hours', '8');
formData.append('start_work_time', '08:00');
formData.append('end_work_time', '16:00');
formData.append('hourly_wage', '150000');
formData.append('overtime_hourly_wage', '200000');
formData.append('attendence_tracking_enabled', '1');
formData.append('image', imageFile); // File object from input

const response = await axios.post('/api/users', formData, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'multipart/form-data'
  }
});
```

### Update User

```javascript
const response = await axios.put(`/api/users/${userId}`, {
  name: 'John Doe Updated',
  mobile: '09123456789',
  role: 'admin',
  farm_id: 2
}, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json'
  }
});
```

### Delete User

```javascript
const response = await axios.delete(`/api/users/${userId}`, {
  headers: {
    'Authorization': `Bearer ${token}`
  }
});
```

---

**Version:** 1.0 | **Last Updated:** February 2026
